<?php

namespace Database\Seeders;

use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Enums\RfqStatus;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\User;
use App\Services\ChatCommerceService;
use App\Services\LeadFlowService;
use App\Services\OrderLifecycleService;
use App\Services\ReorderService;
use App\Services\RfqTriageService;
use Illuminate\Database\Seeder;
use RuntimeException;
use Throwable;

/**
 * Runs the Phase 4 reorder chain on top of a completed demo order, so the
 * mockup's screens have something real behind them locally.
 *
 * The chain it produces is exactly the chain a human would produce, through the
 * same services and in the same order:
 *
 *   buyer reorders  -> REORDER REQUEST card (a new, private RFQ at `new`)
 *   admin triages   -> RfqTriageService::approve() then ::route() to the
 *                      supplier. NOT skipped and not faked: until this happens
 *                      the supplier genuinely cannot price the request, so a
 *                      demo that jumped over it would show a state the app
 *                      cannot actually reach.
 *   supplier prices -> QUOTATION card (Phase 2, reused)
 *   buyer accepts   -> CONTRACT ACCEPTANCE + ORDER REFERENCE cards, and a
 *                      genuinely new Order through QuoteService::accept()
 *   supplier issues -> PROFORMA INVOICE + PAYMENT REQUEST cards (Phase 3)
 *   supplier records-> PAYMENT CONFIRMED card, then the order moves to
 *                      confirmed / in production
 *
 * Nothing is fabricated. It only ever reuses a COMPLETED order that already
 * exists on a conversation; if it cannot find one it does nothing and says so.
 * The reorder's prices are entered here as the supplier's own new figures — the
 * seeder deliberately quotes a different unit price from the source order, so
 * the demo cannot accidentally suggest that last time's price carried over.
 *
 * Idempotent: it stops at the first step that has already happened, and the
 * database's partial unique index on `rfqs.reorder_of_order_id` makes a second
 * run incapable of creating a second open reorder even if it tried. Run it
 * standalone — `php artisan db:seed --class=ReorderSeeder` — since the full
 * DatabaseSeeder is known not to be re-runnable.
 */
class ReorderSeeder extends Seeder
{
    public function run(): void
    {
        $reorders = app(ReorderService::class);
        $commerce = app(ChatCommerceService::class);
        $lifecycle = app(OrderLifecycleService::class);

        $conversation = Conversation::query()
            ->with(['company.users', 'user'])
            ->whereHas('user')
            ->whereHas('company.users')
            ->get()
            ->first(fn (Conversation $c) => $this->sourceOrder($c) !== null);

        if ($conversation === null) {
            $this->command?->warn('ReorderSeeder: no conversation with a completed order behind it. Run OrderLifecycleSeeder first.');

            return;
        }

        $source = $this->sourceOrder($conversation);
        $buyer = $conversation->user;
        $supplier = $conversation->company->users->first();

        // Idempotency. The DOMAIN deliberately allows a buyer to reorder the
        // same order again once the previous reorder has closed — that is the
        // whole point of a reorder — so "no open reorder" is not enough of a
        // guard for a seeder. It stops as soon as this source order has already
        // produced a reorder, rather than adding a fresh chain on every run.
        if (Order::where('reorder_of_order_id', $source->getKey())->exists()) {
            $this->command?->info('ReorderSeeder: order '.$source->reference_code.' has already been reordered. Nothing to do.');

            return;
        }

        try {
            // 1. The buyer asks to repeat it. Quantities only — the request
            //    carries no price, and the card says so on its face.
            $rfq = $reorders->openReorderFor($source);

            if ($rfq === null) {
                $card = $reorders->request($conversation, $source, $buyer, [
                    'quantities' => $source->items
                        ->mapWithKeys(fn ($item) => [(int) $item->getKey() => (string) $item->quantity])
                        ->all(),
                    'notes' => 'Same specification as last time, please.',
                ]);

                $rfq = $card->related;
            }

            // 2. Admin triage. A reorder RFQ is NOT auto-approved — it arrives
            //    at `new` like any other request — so the demo has to walk it
            //    through the real triage service before the supplier can see
            //    it. approve() then route(), both idempotent: route() only
            //    creates the RfqCompany row (and the lead, and the exporter
            //    notification) when one does not already exist.
            $admin = $this->triageActor($buyer);

            if ($rfq->refresh()->status !== RfqStatus::Approved) {
                app(RfqTriageService::class)->approve($rfq, $admin);
            }

            app(RfqTriageService::class)->route(
                $rfq->refresh(),
                [$conversation->company_id],
                $admin,
                app(LeadFlowService::class),
            );

            // 3. The supplier prices it. These are NEW figures the supplier
            //    states today — deliberately not the source order's prices.
            $quote = $rfq->quotes()->where('company_id', $conversation->company_id)->first();

            if ($quote === null) {
                $quote = $reorders->quote($conversation->refresh(), $rfq->refresh(), $supplier, [
                    'lines' => $rfq->items->mapWithKeys(fn ($item) => [
                        (int) $item->getKey() => [
                            'unit_price' => $this->repricedUnitPrice($source),
                        ],
                    ])->all(),
                    'lead_time_days' => 21,
                    'validity_days' => 14,
                    'payment_terms' => '50% advance, 50% before shipment',
                    'shipping_amount' => $source->shipping_amount,
                ]);
            }

            // 4. The buyer accepts through the unchanged Phase 2 path, which
            //    mints the new order, its receipt and its cards.
            if ($quote->refresh()->status->isOpen()) {
                $commerce->acceptQuotation($conversation->refresh(), $quote->refresh(), $buyer);
            }

            $newOrder = Order::where('quote_id', $quote->getKey())->first();

            if ($newOrder === null) {
                $this->command?->warn('ReorderSeeder: the reorder quotation was not accepted, so no new order exists yet.');

                return;
            }

            // 5-7. The Phase 3 cards, reused verbatim on the new order.
            $lifecycle->issueProformaInvoice($conversation->refresh(), $newOrder, $supplier);

            if (! $this->hasCardForOrder($conversation, MessageType::PaymentRequest, $newOrder)) {
                $lifecycle->requestPayment($conversation->refresh(), $newOrder->refresh(), $supplier, now()->addDays(7)->toDateString());
            }

            if (! $this->hasCardForOrder($conversation, MessageType::PaymentConfirmed, $newOrder)) {
                $lifecycle->recordPayment($conversation->refresh(), $newOrder->refresh(), $supplier, $newOrder->total_amount, 'Bank transfer');
            }

            // "Payment confirmed -> order processing": the processing state is
            // the real status machine moving, not a decorative step.
            if ($newOrder->refresh()->status === OrderStatus::Awarded) {
                $lifecycle->confirm($conversation->refresh(), $newOrder->refresh(), $supplier);
            }

            if ($newOrder->refresh()->status === OrderStatus::Confirmed) {
                $lifecycle->startProduction($conversation->refresh(), $newOrder->refresh(), $supplier);
            }

            $this->command?->info(sprintf(
                'ReorderSeeder: %s reordered as %s (now %s) on conversation #%d.',
                $source->reference_code,
                $newOrder->refresh()->reference_code,
                $newOrder->status->label(),
                $conversation->getKey(),
            ));
        } catch (Throwable $e) {
            $this->command?->warn('ReorderSeeder: stopped — '.$e->getMessage());
        }
    }

    /**
     * A real staff account to attribute the triage decisions to.
     *
     * The approval is written to the activity log against whoever this is, so
     * it must not be the buyer or the supplier — attributing an admin decision
     * to a party in the trade would put a false name on an audit record. If no
     * staff account exists the seeder stops rather than inventing one.
     */
    private function triageActor(User $buyer): User
    {
        $admin = User::role(['super_admin', 'admin'])->whereKeyNot($buyer->getKey())->first();

        if ($admin === null) {
            throw new RuntimeException('no admin or super_admin account to attribute triage to. Run RolesAndPermissionsSeeder and create a staff user first.');
        }

        return $admin;
    }

    /**
     * A visibly different unit price, so the demo can never be mistaken for
     * "the old price carried over".
     */
    private function repricedUnitPrice(Order $source): string
    {
        $previous = (float) ($source->items->first()?->unit_price ?? 100);

        return number_format(round($previous * 1.05, 2), 2, '.', '');
    }

    private function hasCardForOrder(Conversation $conversation, MessageType $type, Order $order): bool
    {
        return $conversation->messages()
            ->where('type', $type->value)
            ->where('related_type', $order->getMorphClass())
            ->where('related_id', $order->getKey())
            ->exists();
    }

    /** A completed order that genuinely belongs to both sides of this thread. */
    private function sourceOrder(Conversation $conversation): ?Order
    {
        return Order::with('items')
            ->where('company_id', $conversation->company_id)
            ->where('user_id', $conversation->user_id)
            ->where('status', OrderStatus::Completed->value)
            ->latest('id')
            ->first();
    }
}
