<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Enums\OrderDocumentKind;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\CompanyReview;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The order lifecycle as it is driven from inside a conversation.
 *
 * This is Phase 3's single decision point, in the same spirit as
 * ChatCommerceService: nothing else decides who may advance an order, and
 * nothing else writes a lifecycle card. It delegates every status move to
 * OrderService, which remains the only place `orders.status` is written and the
 * only place illegal transitions are refused — so the guard here is about the
 * ACTOR, and the guard there is about the MOVE, and both must pass.
 *
 * Who may do what
 * ---------------
 *   supplier only : confirm, start production, ship, record tracking, mark
 *                   delivered, upload documents, publish payment instructions
 *   buyer only    : complete (confirm receipt), review the supplier
 *   either        : nothing that changes state
 *
 * A non-participant never reaches any of it: every entry point re-resolves the
 * conversation through MessagingService, which 404s (not 403s) for a stranger,
 * matching Phases 1–2 exactly. A participant on the wrong side gets 403,
 * because at that point the thread's existence is not a secret from them.
 *
 * ⚠ Payments. This platform has NO payment integration and this service adds
 * none. `recordPayment()` reflects money that moved somewhere else, is refused
 * to the buyer (who could otherwise mark their own order paid), and goes
 * through OrderService::recordPayment() so it lands in the activity log. No
 * method here accepts a card number, a bank credential or an account detail of
 * any kind, and nothing anywhere implies funds were transferred by us.
 */
class OrderLifecycleService
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly ChatCommerceService $commerce,
        private readonly OrderService $orders,
        private readonly OrderDocumentService $documents,
        private readonly CompanyReviewService $reviews,
    ) {}

    /* --------------------------------------------------------------- guards */

    /**
     * Resolve the order that belongs to THIS thread.
     *
     * `abort(404)` rather than firstOrFail(): an order id belonging to another
     * conversation must be indistinguishable from one that does not exist.
     */
    public function threadOrder(Conversation $conversation, int $orderId): Order
    {
        return Order::where('company_id', $conversation->company_id)
            ->where('user_id', $conversation->user_id)
            ->whereKey($orderId)
            ->firstOr(fn () => abort(404));
    }

    /** The order this conversation is actually about, if it has one. */
    public function conversationOrder(Conversation $conversation): ?Order
    {
        return $conversation->order_id
            ? Order::whereKey($conversation->order_id)->first()
            : null;
    }

    /* ----------------------------------------------------- proforma invoice */

    /**
     * Post the proforma invoice card.
     *
     * A proforma invoice here is a DOCUMENT VIEW of the order snapshot and
     * nothing more — the same lines, the same currency, the same totals that
     * OrderService copied at award time. No number is invented for it: it is
     * identified by the order's own reference, because minting an "INV-…"
     * series would imply an invoicing system this platform does not run.
     *
     * It is explicitly not a tax invoice and the card says so.
     */
    public function issueProformaInvoice(Conversation $conversation, Order $order, User $supplier): Message
    {
        $this->commerce->assertSupplier($conversation, $supplier);
        $this->assertOrderOnThread($conversation, $order);

        return $this->onceForOrder($conversation, $order, MessageType::ProformaInvoice, function () use ($conversation, $order, $supplier) {
            $order->loadMissing('items');

            return $this->write($conversation, $supplier, $order, MessageType::ProformaInvoice, [
                'reference_code' => $order->reference_code,
                'issued_at' => now()->toIso8601String(),
                'currency' => $order->currency->value,
                'subtotal_amount' => (string) $order->subtotal_amount,
                'shipping_amount' => (string) $order->shipping_amount,
                'tax_amount' => (string) $order->tax_amount,
                'total_amount' => (string) $order->total_amount,
                'supplier_name' => $order->supplier_name,
                'buyer_name' => $order->buyer_name,
                'buyer_company' => $order->buyer_company,
                'incoterm' => $order->incoterm?->value,
                'payment_terms' => $order->payment_terms,
                'lead_time_days' => $order->lead_time_days,
                'shipping_port' => $order->shipping_port,
                'items' => $order->items->map(fn ($item) => [
                    'description' => $item->description,
                    'species_name' => $item->species_name,
                    'grade' => $item->grade,
                    'dimensions' => $item->dimensions,
                    'quantity' => (string) $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => (string) $item->unit_price,
                    'line_total' => (string) $item->line_total,
                ])->all(),
            ]);
        });
    }

    /* ------------------------------------------------------------ payments */

    /**
     * Ask the buyer to settle.
     *
     * This card takes no money and collects no payment details. It states the
     * real outstanding balance, the payment terms the supplier quoted, an
     * optional due date, and the supplier's own published instructions — all of
     * which are values a human typed. "Pay Now" from the mockup is deliberately
     * absent; there is nothing behind it.
     */
    public function requestPayment(
        Conversation $conversation,
        Order $order,
        User $supplier,
        ?string $dueDate = null,
        ?string $reference = null,
    ): Message {
        $this->commerce->assertSupplier($conversation, $supplier);
        $this->assertOrderOnThread($conversation, $order);

        if ($order->status === OrderStatus::Cancelled) {
            throw new RuntimeException('This order was cancelled. No payment can be requested against it.');
        }

        return DB::transaction(function () use ($conversation, $order, $supplier, $dueDate, $reference) {
            if ($dueDate !== null || $reference !== null) {
                $order->forceFill(array_filter([
                    'payment_due_at' => $dueDate ?: null,
                    'payment_reference' => $reference ? trim($reference) : null,
                ], fn ($v) => $v !== null))->save();

                activity('order')->performedOn($order)->causedBy($supplier)
                    ->event('payment_requested')
                    ->withProperties(['due_at' => $dueDate, 'reference' => $reference])
                    ->log('Supplier issued a payment request');
            }

            $order->refresh();

            return $this->write($conversation, $supplier, $order, MessageType::PaymentRequest, [
                'reference_code' => $order->reference_code,
                'currency' => $order->currency->value,
                // The full order total and the balance as it stood when the
                // request was made. Live settlement is read off the order.
                'total_amount' => (string) $order->total_amount,
                'balance_due' => $order->balanceDue(),
                'payment_terms' => $order->payment_terms,
                'requested_at' => now()->toIso8601String(),
            ]);
        });
    }

    /**
     * Record a payment that happened OFF this platform, and post the card.
     *
     * Supplier side only. The buyer is refused explicitly: if a buyer could
     * call this, they could mark their own order paid in full, which is the
     * single most damaging thing an unauthenticated-by-money system could
     * allow. Platform staff record payments through the admin panel, which
     * calls OrderService::recordPayment() directly.
     */
    public function recordPayment(
        Conversation $conversation,
        Order $order,
        User $supplier,
        float|string $amount,
        ?string $method = null,
    ): Message {
        $this->commerce->assertSupplier($conversation, $supplier);
        $this->assertOrderOnThread($conversation, $order);

        if ($order->status === OrderStatus::Cancelled) {
            throw new RuntimeException('This order was cancelled. No payment can be recorded against it.');
        }

        return DB::transaction(function () use ($conversation, $order, $supplier, $amount, $method) {
            // OrderService owns the arithmetic, the status derivation and the
            // activity log entry. Nothing about settlement is re-implemented.
            $updated = $this->orders->recordPayment($order, $amount, $method ? trim($method) : null, $supplier);

            return $this->write($conversation, $supplier, $updated, MessageType::PaymentConfirmed, [
                'reference_code' => $updated->reference_code,
                'currency' => $updated->currency->value,
                'amount_paid' => (string) $updated->amount_paid,
                'payment_method' => $updated->payment_method,
                'recorded_at' => $updated->payment_recorded_at?->toIso8601String(),
                'recorded_by_name' => $supplier->name,
            ]);
        });
    }

    /* ------------------------------------------------ production & shipping */

    /** Supplier confirms the order they were awarded. */
    public function confirm(Conversation $conversation, Order $order, User $supplier): Message
    {
        return $this->advance($conversation, $order, $supplier, fn (Order $o) => $this->orders->confirm($o, $supplier));
    }

    /** Supplier starts production. The card then reads the live status. */
    public function startProduction(Conversation $conversation, Order $order, User $supplier): Message
    {
        return $this->advance($conversation, $order, $supplier, fn (Order $o) => $this->orders->startProduction($o, $supplier));
    }

    /**
     * Supplier ships, optionally recording the shipment facts in the same
     * action so the card has something real to show.
     *
     * @param  array<string, mixed>  $tracking
     */
    public function ship(Conversation $conversation, Order $order, User $supplier, array $tracking = []): Message
    {
        return $this->advance($conversation, $order, $supplier, function (Order $o) use ($supplier, $tracking) {
            if ($tracking !== []) {
                $this->writeTracking($o, $supplier, $tracking);
            }

            return $this->orders->ship($o->refresh(), $supplier);
        });
    }

    /**
     * Supplier marks the goods delivered, recording who received them and
     * where, and attaching proof-of-delivery files if they have any.
     *
     * @param  list<UploadedFile>  $proofFiles
     */
    public function deliver(
        Conversation $conversation,
        Order $order,
        User $supplier,
        ?string $receivedBy = null,
        ?string $location = null,
        array $proofFiles = [],
    ): Message {
        return $this->advance($conversation, $order, $supplier, function (Order $o) use ($supplier, $receivedBy, $location, $proofFiles) {
            $facts = array_filter([
                'delivered_to_name' => $receivedBy ? trim($receivedBy) : null,
                'delivery_location' => $location ? trim($location) : null,
            ], fn ($v) => $v !== null && $v !== '');

            if ($facts !== []) {
                $o->forceFill($facts)->save();
            }

            foreach ($proofFiles as $file) {
                $this->documents->store($o, $file, OrderDocumentKind::ProofOfDelivery, $supplier);
            }

            return $this->orders->deliver($o->refresh(), $supplier);
        }, MessageType::OrderDelivered);
    }

    /**
     * Update the shipment facts without moving the status.
     *
     * @param  array<string, mixed>  $tracking
     */
    public function updateTracking(Conversation $conversation, Order $order, User $supplier, array $tracking): Message
    {
        $this->commerce->assertSupplier($conversation, $supplier);
        $this->assertOrderOnThread($conversation, $order);

        return DB::transaction(function () use ($conversation, $order, $supplier, $tracking) {
            $this->writeTracking($order, $supplier, $tracking);

            return $this->write($conversation, $supplier, $order->refresh(), MessageType::ShipmentUpdate, [
                'reference_code' => $order->reference_code,
            ]);
        });
    }

    /* ---------------------------------------------------------- completion */

    /**
     * The BUYER closes the transaction — they, and only they, can say the goods
     * arrived acceptably. A supplier marking their own order complete would
     * make the completion signal (and therefore review eligibility) worthless.
     */
    public function complete(Conversation $conversation, Order $order, User $buyer): Message
    {
        $this->commerce->assertBuyer($conversation, $buyer);
        $this->assertOrderOnThread($conversation, $order);

        return DB::transaction(function () use ($conversation, $order, $buyer) {
            // OrderService refuses anything that is not delivered -> completed.
            $completed = $this->orders->complete($order, $buyer);

            return $this->write($conversation, $buyer, $completed, MessageType::TransactionCompleted, [
                'reference_code' => $completed->reference_code,
                'currency' => $completed->currency->value,
                'total_amount' => (string) $completed->total_amount,
                'completed_at' => $completed->completed_at?->toIso8601String(),
                'item_count' => $completed->items()->count(),
            ]);
        });
    }

    /* ------------------------------------------------------------ documents */

    /**
     * Attach shipping papers. Supplier side only — a buyer uploading a "bill of
     * lading" into the supplier's document set would let either party plant
     * evidence in a shared record neither can edit afterwards.
     *
     * @param  list<UploadedFile>  $files
     */
    public function attachDocuments(
        Conversation $conversation,
        Order $order,
        User $supplier,
        array $files,
        OrderDocumentKind $kind = OrderDocumentKind::Other,
        ?string $label = null,
    ): Message {
        $this->commerce->assertSupplier($conversation, $supplier);
        $this->assertOrderOnThread($conversation, $order);

        if ($files === []) {
            throw new RuntimeException('Choose at least one document to attach.');
        }

        return DB::transaction(function () use ($conversation, $order, $supplier, $files, $kind, $label) {
            foreach ($files as $file) {
                $this->documents->store($order, $file, $kind, $supplier, $label);
            }

            activity('order')->performedOn($order)->causedBy($supplier)
                ->event('documents_attached')
                ->withProperties(['count' => count($files), 'kind' => $kind->value])
                ->log('Supplier attached order documents');

            // One documents card per order; it lists the set live, so a later
            // upload appears in the card that is already in the thread.
            return $this->onceForOrder($conversation, $order, MessageType::OrderDocuments, fn () => $this->write(
                $conversation,
                $supplier,
                $order,
                MessageType::OrderDocuments,
                ['reference_code' => $order->reference_code],
            ));
        });
    }

    /**
     * May this user download this document?
     *
     * Derived from the ORDER, never from the request: you must be the buyer on
     * the order or a member of the supplying company. There is no signed-URL
     * shortcut and no "anyone with the link" path.
     */
    public function mayAccessDocument(?User $user, OrderDocument $document): bool
    {
        if ($user === null) {
            return false;
        }

        $order = $document->order;

        if ($order === null) {
            return false;
        }

        if ((int) $order->user_id === (int) $user->getKey()) {
            return true;
        }

        return $user->companies()->whereKey($order->company_id)->exists();
    }

    /* -------------------------------------------------------------- reviews */

    /**
     * The buyer reviews the supplier, and the review is posted into the thread.
     *
     * Eligibility lives in CompanyReviewService (completed order, owned by this
     * buyer, not already reviewed) and the database enforces one-per-order.
     */
    public function review(Conversation $conversation, Order $order, User $buyer, array $data): CompanyReview
    {
        $this->commerce->assertBuyer($conversation, $buyer);
        $this->assertOrderOnThread($conversation, $order);

        return DB::transaction(function () use ($conversation, $order, $buyer, $data) {
            $review = $this->reviews->create($order, $buyer, $data, $conversation);

            $card = $this->write($conversation, $buyer, $order, MessageType::CompanyReview, [
                'reference_code' => $order->reference_code,
                'rating' => $review->rating,
                'reviewed_at' => $review->created_at?->toIso8601String(),
                'author_name' => $review->author_name,
            ], $review);

            $review->forceFill(['message_id' => $card->getKey()])->save();

            return $review->refresh();
        });
    }

    /* --------------------------------------------- supplier payment details */

    /**
     * Publish the settlement instructions a buyer should follow. Free text the
     * supplier maintains; the platform neither validates nor uses it.
     */
    public function savePaymentInstructions(Company $company, User $supplier, ?string $instructions): Company
    {
        abort_unless($supplier->companies()->whereKey($company->getKey())->exists(), 403);

        $company->forceFill(['payment_instructions' => trim((string) $instructions) ?: null])->save();

        activity('company')->performedOn($company)->causedBy($supplier)
            ->event('payment_instructions_updated')
            ->log('Supplier updated their payment instructions');

        return $company->refresh();
    }

    /* -------------------------------------------------------------- plumbing */

    /**
     * Supplier-only status move + card, in one transaction.
     *
     * The ACTOR check runs first (403 for the buyer), then OrderService runs
     * the MOVE check (RuntimeException for an illegal transition). Both are
     * necessary: a supplier still cannot ship an unconfirmed order.
     */
    private function advance(
        Conversation $conversation,
        Order $order,
        User $supplier,
        callable $move,
        MessageType $type = MessageType::ShipmentUpdate,
    ): Message {
        $this->commerce->assertSupplier($conversation, $supplier);
        $this->assertOrderOnThread($conversation, $order);

        return DB::transaction(function () use ($conversation, $order, $supplier, $move, $type) {
            /** @var Order $updated */
            $updated = $move($order);

            return $this->write($conversation, $supplier, $updated, $type, [
                'reference_code' => $updated->reference_code,
                'status_at_post' => $updated->status->value,
            ]);
        });
    }

    /** @param array<string, mixed> $tracking */
    private function writeTracking(Order $order, User $supplier, array $tracking): void
    {
        $allowed = [
            'carrier', 'tracking_number', 'tracking_url', 'shipping_method',
            'vessel_name', 'voyage_number', 'container_number',
            'port_of_loading', 'port_of_discharge', 'etd', 'eta',
        ];

        $clean = [];

        foreach ($allowed as $field) {
            if (! array_key_exists($field, $tracking)) {
                continue;
            }

            $value = $tracking[$field];
            $value = is_string($value) ? trim($value) : $value;

            // An explicitly blank value clears the field rather than storing
            // an empty string, so shipmentFacts() keeps dropping it.
            $clean[$field] = ($value === '' || $value === null) ? null : $value;
        }

        if ($clean === []) {
            throw new RuntimeException('Enter at least one shipment detail.');
        }

        $order->forceFill($clean)->save();

        activity('order')->performedOn($order)->causedBy($supplier)
            ->event('tracking_updated')
            ->withProperties(['fields' => array_keys($clean)])
            ->log('Supplier updated the shipment details');
    }

    /**
     * Cards that should exist at most once per order (the proforma invoice, the
     * document folder). Re-running the action returns the existing card rather
     * than stacking duplicates in the thread — and because both read their
     * live half off the order, the existing card is already up to date.
     */
    private function onceForOrder(Conversation $conversation, Order $order, MessageType $type, callable $make): Message
    {
        $existing = $conversation->messages()
            ->where('type', $type->value)
            ->where('related_type', $order->getMorphClass())
            ->where('related_id', $order->getKey())
            ->first();

        return $existing ?: $make();
    }

    /** @param array<string, mixed> $payload */
    private function write(
        Conversation $conversation,
        ?User $sender,
        Order $order,
        MessageType $type,
        array $payload,
        ?CompanyReview $review = null,
    ): Message {
        // The review card points at the review (its live half is moderation
        // status); every other lifecycle card points at the order.
        $related = $review ?? $order;

        $message = $conversation->messages()->create([
            'sender_user_id' => $sender?->getKey(),
            'type' => $type->value,
            'body' => null,
            'payload' => $payload,
            'related_type' => $related->getMorphClass(),
            'related_id' => $related->getKey(),
        ]);

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
            'order_id' => $conversation->order_id ?? $order->getKey(),
        ])->save();

        return $message;
    }

    /**
     * The order must belong to both sides of this thread. Without this, a
     * supplier who trades with two buyers could push buyer A's order card into
     * buyer B's conversation.
     */
    private function assertOrderOnThread(Conversation $conversation, Order $order): void
    {
        abort_unless(
            (int) $order->company_id === (int) $conversation->company_id
                && (int) $order->user_id === (int) $conversation->user_id,
            404,
        );
    }
}
