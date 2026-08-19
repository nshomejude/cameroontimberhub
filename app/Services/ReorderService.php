<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Enums\RfqStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reorder: repeating a past purchase from inside the conversation.
 *
 * The model, and why
 * ------------------
 * A reorder does NOT clone an Order row and reset its status. An order is the
 * settled record of one agreement; copying it would mint money and a contract
 * that no supplier ever offered and no buyer ever accepted, and would leave the
 * receipt, the activity log and the quote provenance describing a transaction
 * that did not happen.
 *
 * Instead a reorder is a REQUEST that re-enters the existing, audited path:
 *
 *      buyer: request()  ->  a new Rfq (private, routed to that one supplier)
 *      supplier: quote() ->  a new Quote, priced by the supplier, submitted
 *      buyer: accept     ->  ChatCommerceService::acceptQuotation()
 *                            -> QuoteService::accept() (lock, decline siblings,
 *                               close the RFQ) -> OrderService::createFromQuote()
 *                            -> a NEW Order, with its own receipt and reference
 *
 * Every guarantee the platform already makes therefore holds unchanged: one
 * accepted quote per RFQ, one order per quote, a contract-acceptance record
 * against the terms the buyer actually saw, and an activity trail throughout.
 * The source order is never written to.
 *
 * Pricing — the crux
 * ------------------
 * The buyer supplies the SPECIFICATION (which lines, how much of each,
 * destination, deadline, notes). The buyer never supplies a price, and this
 * service accepts no price field from the buyer at all: request() reads only
 * quantities and text, and the RFQ it writes has no price column to put one in.
 *
 * The previous unit prices are carried into the request card ONLY as reference
 * material, snapshotted from the source order and rendered under an explicit
 * "previous price — subject to confirmation" label. They are not defaults that
 * silently become the new price: quote() requires the supplier to state a unit
 * price for every line, and QuoteService::recalculate() then derives every
 * total from the supplier's own figures. There is no code path by which a
 * number a buyer typed, or a number from last time, becomes the price of the
 * new order.
 *
 * Idempotency
 * -----------
 * A partial unique index on `rfqs (reorder_of_order_id) WHERE status IN
 * ('new','in_review','approved')` allows at most one OPEN reorder per source
 * order. The PHP pre-check returns the existing card, and a lost race raises a
 * unique violation that is turned into the same refusal — so a double click, a
 * double tab or a replayed POST produces exactly one reorder RFQ and therefore
 * at most one new order.
 */
class ReorderService
{
    /**
     * Statuses a reorder may be started from.
     *
     * Delivered and completed only: the goods actually arrived. A cancelled
     * order is explicitly NOT here — reordering from one would resurrect a
     * transaction that both parties walked away from — and neither is an order
     * still in flight, because "order again" is meaningless before the first
     * one landed.
     *
     * @var list<OrderStatus>
     */
    public const ELIGIBLE_STATUSES = [OrderStatus::Delivered, OrderStatus::Completed];

    public function __construct(
        private readonly MessagingService $messaging,
        private readonly ChatCommerceService $commerce,
        private readonly IntakeService $intake,
        private readonly QuoteService $quotes,
        private readonly LeadFlowService $leads,
    ) {}

    /* --------------------------------------------------------- eligibility */

    /**
     * May $user start a reorder from $order?
     *
     * Buyer-owned and landed. Deliberately mirrors CompanyReviewService::
     * canReview() in shape: the view asks this to decide whether to draw the
     * button, and assertEligible() below refuses regardless of what the view
     * drew.
     */
    public function canReorder(?User $user, Order $order): bool
    {
        return $user !== null
            && (int) $order->user_id === (int) $user->getKey()
            && in_array($order->status, self::ELIGIBLE_STATUSES, true);
    }

    /**
     * 403 for "not your order" — an authorisation failure, not a domain one —
     * and a RuntimeException for a genuine-but-ineligible order, which is
     * something the buyer can see for themselves and is safe to explain.
     */
    public function assertEligible(User $buyer, Order $order): void
    {
        abort_unless((int) $order->user_id === (int) $buyer->getKey(), 403, 'Only the buyer on an order can reorder it.');

        if ($order->status === OrderStatus::Cancelled) {
            throw new RuntimeException('This order was cancelled. Start a new request instead of reordering it.');
        }

        if (! in_array($order->status, self::ELIGIBLE_STATUSES, true)) {
            throw new RuntimeException('You can reorder once this order has been delivered.');
        }
    }

    /** The open reorder request against this order, if one is already running. */
    public function openReorderFor(Order $order): ?Rfq
    {
        return Rfq::where('reorder_of_order_id', $order->getKey())
            ->whereIn('status', [RfqStatus::New->value, RfqStatus::InReview->value, RfqStatus::Approved->value])
            ->first();
    }

    /**
     * The previous figures, for display only.
     *
     * Every value is labelled by the caller as a past price. Nothing here is
     * fed back into a write path.
     *
     * @return list<array<string, mixed>>
     */
    public function previousLines(Order $order): array
    {
        $order->loadMissing('items');

        return $order->items->map(fn ($item) => [
            'order_item_id' => (int) $item->getKey(),
            'description' => $item->description,
            'species_name' => $item->species_name,
            'form' => $item->form,
            'grade' => $item->grade,
            'dimensions' => $item->dimensions,
            'quantity' => (string) $item->quantity,
            'unit' => $item->unit,
            'previous_unit_price' => (string) $item->unit_price,
        ])->all();
    }

    /* ------------------------------------------------------- buyer: request */

    /**
     * Buyer asks the supplier to repeat a past order.
     *
     * $data may carry `quantities` (order item id => new quantity), plus
     * `shipping_port`, `deadline` and `notes`. It may NOT carry a price, and
     * any price-shaped key in it is ignored because it is never read.
     *
     * @param  array<string, mixed>  $data
     */
    public function request(Conversation $conversation, Order $source, User $buyer, array $data = []): Message
    {
        // Participant first (a stranger 404s inside party()), then side.
        $this->commerce->assertBuyer($conversation, $buyer);
        $this->assertOrderOnThread($conversation, $source);
        $this->assertEligible($buyer, $source);

        // Fast path: an open reorder already exists, so return its card rather
        // than starting a second one. The unique index below is what actually
        // makes this safe under concurrency; this is only the friendly half.
        if ($existing = $this->openReorderFor($source)) {
            if ($card = $this->cardFor($conversation, $existing)) {
                return $card;
            }
        }

        $source->loadMissing('items');

        if ($source->items->isEmpty()) {
            throw new RuntimeException('That order has no line items to repeat.');
        }

        $quantities = $this->cleanQuantities($source, $data['quantities'] ?? []);

        try {
            $rfq = $this->intake->createRfq(
                [
                    'title' => Str::limit('Reorder of '.$source->reference_code, 170, ''),
                    'buyer_name' => $buyer->name,
                    'buyer_email' => strtolower(trim($buyer->email)),
                    'buyer_company' => $source->buyer_company,
                    'incoterm' => $source->incoterm?->value,
                    'shipping_port' => $this->text($data['shipping_port'] ?? null, 120) ?? $source->shipping_port,
                    'destination_country_code' => $source->destination_country_code,
                    'target_currency' => $source->currency->value,
                    'deadline' => $data['deadline'] ?? null,
                    'notes' => $this->text($data['notes'] ?? null, 1000),
                    'reorder_of_order_id' => $source->getKey(),
                ],
                $source->items->map(fn ($item) => [
                    'species_id' => $item->species_id,
                    'species_text' => $item->species_name ?: $item->description,
                    'form' => $item->form,
                    'grade' => $item->grade,
                    'dimensions' => $item->dimensions,
                    'quantity' => $quantities[(int) $item->getKey()] ?? $item->quantity,
                    'unit' => $item->unit,
                ])->all(),
                'reorder',
            );
        } catch (QueryException $e) {
            // The partial unique index fired: another request for this same
            // source order won the race. Same outcome as the pre-check.
            if ($this->isUniqueViolation($e)) {
                throw new RuntimeException('A reorder request for this order is already open.');
            }

            throw $e;
        }

        return DB::transaction(function () use ($conversation, $source, $buyer, $rfq, $quantities) {
            // Same rule as the chat RFQ: the verification gate is SATISFIED,
            // never skipped, and only when this platform already verified this
            // exact address. Otherwise the signed email link stays the way in.
            if ($this->commerce->emailAlreadyVerifiedFor($buyer, $rfq)) {
                $this->intake->verifyRfq($rfq);
            }

            $rfq->refresh();

            // A reorder is a private, directed repeat of a trade this platform
            // already recorded between these two parties, so it is approved and
            // routed to that one supplier rather than sitting in public triage.
            // Both moves are logged against the buyer who caused them.
            $rfq->forceFill(['visibility' => 'private'])->save();

            if ($rfq->status !== RfqStatus::Approved) {
                $rfq->forceFill(['status' => RfqStatus::Approved->value])->save();

                activity('rfq')->performedOn($rfq)->causedBy($buyer)->event('status_changed')
                    ->withProperties(['to' => RfqStatus::Approved->value, 'reason' => 'reorder'])
                    ->log('RFQ status -> approved');
            }

            $routing = RfqCompany::firstOrCreate(
                ['rfq_id' => $rfq->getKey(), 'company_id' => $source->company_id],
                ['status' => 'sent', 'routed_at' => now()],
            );

            if ($routing->wasRecentlyCreated) {
                $this->leads->createFromRouting($routing);
            }

            activity('order')->performedOn($source)->causedBy($buyer)->event('reorder_requested')
                ->withProperties(['rfq_id' => $rfq->getKey(), 'conversation_id' => $conversation->getKey()])
                ->log('Buyer requested a reorder');

            return $this->writeCard($conversation, $buyer, $source, $rfq->refresh(), $quantities);
        });
    }

    /* ------------------------------------------------------ supplier: quote */

    /**
     * Supplier prices the reorder and puts the quotation in the thread.
     *
     * This is the confirmation step, and it is not optional: without it there
     * is no quote, without a quote there is nothing for the buyer to accept,
     * and without an acceptance no order exists. `lines` must carry a unit
     * price per order line and those prices are the ONLY source of the money on
     * the resulting quote — QuoteService::recalculate() re-derives every line
     * total and every header total from them, exactly as it does for a quote
     * typed in the exporter panel.
     *
     * @param  array<string, mixed>  $data
     */
    public function quote(Conversation $conversation, Rfq $rfq, User $supplier, array $data): Quote
    {
        $this->commerce->assertSupplier($conversation, $supplier);
        $this->assertReorderOnThread($conversation, $rfq);

        $rfq->loadMissing('items');
        $conversation->loadMissing('company');
        $company = $conversation->company;

        $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];

        $quote = DB::transaction(function () use ($rfq, $company, $supplier, $data, $lines) {
            $quote = $this->quotes->open($rfq, $company, array_filter([
                'currency' => $rfq->target_currency ?: null,
                'incoterm' => $rfq->incoterm?->value,
                'lead_time_days' => isset($data['lead_time_days']) && $data['lead_time_days'] !== ''
                    ? (int) $data['lead_time_days'] : null,
                'validity_days' => isset($data['validity_days']) && $data['validity_days'] !== ''
                    ? (int) $data['validity_days'] : null,
                'valid_until' => isset($data['validity_days']) && $data['validity_days'] !== ''
                    ? now()->addDays((int) $data['validity_days'])->toDateString() : null,
                'payment_terms' => $this->text($data['payment_terms'] ?? null, 255),
                'shipping_amount' => isset($data['shipping_amount']) && $data['shipping_amount'] !== ''
                    ? $data['shipping_amount'] : null,
            ], fn ($v) => $v !== null));

            foreach ($rfq->items as $index => $item) {
                $price = $lines[$item->getKey()]['unit_price'] ?? ($lines[$index]['unit_price'] ?? null);

                if ($price === null || $price === '' || ! is_numeric($price) || (float) $price <= 0) {
                    throw new RuntimeException('Enter a unit price for every line before sending the quotation.');
                }

                $quantity = $item->quantity ?? 0;

                $quote->items()->create([
                    'rfq_item_id' => $item->getKey(),
                    'species_id' => $item->species_id,
                    'description' => $item->species_text,
                    // Both models cast these, so the enum instance / backing
                    // string passes straight through without a manual unwrap.
                    'form' => $item->form,
                    'grade' => $item->grade,
                    'dimensions' => $item->dimensions,
                    'quantity' => $quantity,
                    'unit' => $item->unit,
                    'unit_price' => $price,
                    'line_total' => Quote::lineTotal($quantity, $price),
                ]);
            }

            // submit() recomputes every figure from the line items it just
            // wrote, so the buyer reads our arithmetic on the supplier's prices.
            return $this->quotes->submit($quote->refresh(), $supplier);
        });

        // Reuses the Phase 2 quotation card verbatim. A reorder quotation is a
        // quotation; giving it a lookalike card of its own would mean two
        // places to keep the accept/counter/withdraw rules correct.
        $this->commerce->issueQuotation($conversation, $quote->refresh(), $supplier);

        activity('rfq')->performedOn($rfq)->causedBy($supplier)->event('reorder_quoted')
            ->withProperties(['quote_id' => $quote->getKey()])
            ->log('Supplier priced a reorder request');

        return $quote->refresh();
    }

    /* -------------------------------------------------------------- helpers */

    /** The reorder-request card for an RFQ on this thread, if it was posted. */
    public function cardFor(Conversation $conversation, Rfq $rfq): ?Message
    {
        return $conversation->messages()
            ->where('type', MessageType::ReorderRequest->value)
            ->where('related_type', $rfq->getMorphClass())
            ->where('related_id', $rfq->getKey())
            ->first();
    }

    /**
     * Quantities the buyer asked for, keyed by order item id.
     *
     * Quantity IS a legitimate buyer input — it is what they want, not what it
     * costs — so it is accepted, but only for lines that belong to this order,
     * only as a positive number, and only within a sane bound. Anything else
     * falls back to the previous quantity.
     *
     * @param  mixed  $input
     * @return array<int, string>
     */
    private function cleanQuantities(Order $order, $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $allowed = $order->items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $clean = [];

        foreach ($input as $itemId => $quantity) {
            $itemId = (int) $itemId;

            if (! in_array($itemId, $allowed, true) || ! is_numeric($quantity)) {
                continue;
            }

            $quantity = (float) $quantity;

            if ($quantity <= 0 || $quantity > 99999999) {
                continue;
            }

            $clean[$itemId] = number_format($quantity, 2, '.', '');
        }

        return $clean;
    }

    /**
     * The request card.
     *
     * Payload snapshots the SPECIFICATION being asked for and, separately and
     * explicitly labelled, the previous unit prices — so the card can show what
     * was paid last time without ever implying it is the offer this time. The
     * live half is the RFQ: its status is what tells the reader whether the
     * supplier has answered yet.
     *
     * @param  array<int, string>  $quantities
     */
    private function writeCard(Conversation $conversation, User $buyer, Order $source, Rfq $rfq, array $quantities): Message
    {
        $lines = collect($this->previousLines($source))->map(function (array $line) use ($quantities) {
            $line['requested_quantity'] = $quantities[$line['order_item_id']] ?? $line['quantity'];

            return $line;
        })->all();

        $message = $conversation->messages()->create([
            'sender_user_id' => $buyer->getKey(),
            'type' => MessageType::ReorderRequest->value,
            'body' => null,
            'payload' => [
                'source_reference_code' => $source->reference_code,
                'source_order_id' => (int) $source->getKey(),
                'source_completed_at' => ($source->completed_at ?? $source->delivered_at)?->toIso8601String(),
                'rfq_reference_code' => $rfq->reference_code,
                'currency' => $source->currency->value,
                'requested_at' => now()->toIso8601String(),
                'shipping_port' => $rfq->shipping_port,
                'incoterm' => $rfq->incoterm?->value,
                'notes' => $rfq->notes,
                // Reference only. The card labels every one of these as a past
                // price that the supplier must confirm or replace.
                'lines' => $lines,
            ],
            'related_type' => $rfq->getMorphClass(),
            'related_id' => $rfq->getKey(),
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'rfq_id' => $rfq->getKey(),
        ])->save();

        return $message;
    }

    /** The source order must belong to both sides of this very thread. */
    private function assertOrderOnThread(Conversation $conversation, Order $order): void
    {
        abort_unless(
            (int) $order->company_id === (int) $conversation->company_id
                && (int) $order->user_id === (int) $conversation->user_id,
            404,
        );
    }

    /**
     * The RFQ must be a reorder raised by this thread's buyer against an order
     * with this thread's supplier — otherwise a supplier sitting on two
     * companies could quote a request that was never routed to them.
     */
    private function assertReorderOnThread(Conversation $conversation, Rfq $rfq): void
    {
        abort_if($rfq->reorder_of_order_id === null, 404);

        $source = Order::whereKey($rfq->reorder_of_order_id)->first();

        abort_unless(
            $source !== null
                && (int) $source->company_id === (int) $conversation->company_id
                && (int) $source->user_id === (int) $conversation->user_id,
            404,
        );
    }

    private function text(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? '') === '23505' || str_contains($e->getMessage(), 'Unique violation');
    }
}
