<?php

namespace Database\Seeders;

use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\Order;
use App\Services\OrderLifecycleService;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Walks one demo order through the whole in-thread lifecycle, so the Phase 3
 * cards have something real behind them locally.
 *
 * Nothing is fabricated. The seeder only ever *reuses* an order that already
 * exists on a conversation — it never mints one, because an order without an
 * accepted quote behind it would be a lie the rest of the app would have to
 * keep believing. If it cannot find such a pairing it does nothing and says so.
 *
 * Two things it deliberately does NOT seed:
 *   - documents. There are no real PDFs to upload, and writing placeholder
 *     bytes to the private disk so the document list looks populated would
 *     manufacture exactly the evidence this platform must never manufacture.
 *   - a review. A review is a sentence a human means; generating one would put
 *     invented words in a named buyer's mouth and move a supplier's public
 *     rating on the strength of them. The completion card renders its review
 *     PROMPT for the demo buyer instead, which is the honest half of that
 *     screen.
 *
 * Idempotent: every action is guarded on the order's real status, and the
 * once-per-order cards (proforma, documents) are idempotent in the service.
 * Run it standalone — `php artisan db:seed --class=OrderLifecycleSeeder` —
 * since the full DatabaseSeeder is known not to be re-runnable.
 */
class OrderLifecycleSeeder extends Seeder
{
    public function run(): void
    {
        $lifecycle = app(OrderLifecycleService::class);

        $conversation = Conversation::query()
            ->with(['company.users', 'user'])
            ->whereHas('user')
            ->whereHas('company.users')
            ->get()
            ->first(fn (Conversation $c) => $this->orderFor($c) !== null);

        if ($conversation === null) {
            $this->command?->warn('OrderLifecycleSeeder: no conversation with an order behind it. Run QuoteSeeder, OrderSeeder, MessagingSeeder and ChatCommerceSeeder first.');

            return;
        }

        $order = $this->orderFor($conversation);
        $buyer = $conversation->user;
        $supplier = $conversation->company->users->first();

        try {
            // 1. Proforma invoice — idempotent per order.
            $lifecycle->issueProformaInvoice($conversation, $order, $supplier);

            // 2. Payment request, with a real due date.
            if (! $this->hasCard($conversation, MessageType::PaymentRequest)) {
                $lifecycle->requestPayment(
                    $conversation,
                    $order->refresh(),
                    $supplier,
                    now()->addDays(7)->toDateString(),
                );
            }

            // 3. A part payment recorded off-platform — deliberately partial,
            //    so the demo shows the "part payment recorded" state rather
            //    than implying a completed settlement.
            if (! $this->hasCard($conversation, MessageType::PaymentConfirmed)) {
                $lifecycle->recordPayment(
                    $conversation,
                    $order->refresh(),
                    $supplier,
                    round((float) $order->total_amount / 2, 2),
                    'Bank transfer',
                );
            }

            // 4-6. Advance as far as the real state machine allows, each step
            //      guarded on the status the order actually has.
            $this->stepIf($order, OrderStatus::Awarded, fn (Order $o) => $lifecycle->confirm($conversation, $o, $supplier));
            $this->stepIf($order, OrderStatus::Confirmed, fn (Order $o) => $lifecycle->startProduction($conversation, $o, $supplier));

            $this->stepIf($order, OrderStatus::InProduction, fn (Order $o) => $lifecycle->ship($conversation, $o, $supplier, [
                // Plausible, supplier-entered shipment facts. Several fields
                // are left empty on purpose so the demo also shows that an
                // unfilled field renders nothing at all.
                'carrier' => 'Maersk Line',
                'tracking_number' => 'MSKU123456789',
                'shipping_method' => 'Sea freight (FCL)',
                'port_of_loading' => 'Douala Port, Cameroon',
                'etd' => now()->toDateString(),
            ]));

            $this->stepIf($order, OrderStatus::Shipped, fn (Order $o) => $lifecycle->deliver(
                $conversation,
                $o,
                $supplier,
                $o->buyer_name,
                $o->shipping_port ?: null,
            ));

            // 7. The BUYER closes it — the supplier cannot, by design.
            $this->stepIf($order, OrderStatus::Delivered, fn (Order $o) => $lifecycle->complete($conversation, $o, $buyer));

            $this->command?->info(sprintf(
                'OrderLifecycleSeeder: order %s is now %s on conversation #%d.',
                $order->refresh()->reference_code,
                $order->status->label(),
                $conversation->getKey(),
            ));
        } catch (Throwable $e) {
            $this->command?->warn('OrderLifecycleSeeder: stopped — '.$e->getMessage());
        }
    }

    /** Run $move only if the order is genuinely in $expected right now. */
    private function stepIf(Order $order, OrderStatus $expected, callable $move): void
    {
        if ($order->refresh()->status === $expected) {
            $move($order);
        }
    }

    private function hasCard(Conversation $conversation, MessageType $type): bool
    {
        return $conversation->messages()->where('type', $type->value)->exists();
    }

    /** An order that genuinely belongs to both sides of this thread. */
    private function orderFor(Conversation $conversation): ?Order
    {
        return Order::where('company_id', $conversation->company_id)
            ->where('user_id', $conversation->user_id)
            ->whereNotIn('status', [OrderStatus::Cancelled->value])
            ->latest('id')
            ->first();
    }
}
