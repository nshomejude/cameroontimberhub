<?php

namespace App\Observers;

use App\Domain\Trade\Events\OrderAwarded;
use App\Models\Order;
use App\Support\Events\RecordsOutboxEvents;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wires real Order creation into the transactional outbox (architecture
 * plan, Task 0.2).
 *
 * When an Order is created (== awarded — see App\Services\OrderService's
 * class doc), this records an OrderAwarded domain event to the outbox
 * inside the same DB transaction as the Order insert (OrderService::
 * createFromQuote() wraps the whole thing in DB::transaction(), and this
 * observer's created() callback fires while that transaction is still
 * open). The compliance-case side effect this observer used to perform
 * inline (evaluate ComplianceRule::applicableTo() and open a
 * ComplianceCase) has moved to App\Listeners\OpenComplianceCaseOnOrderAwarded,
 * a queued listener triggered asynchronously once App\Jobs\RelayOutboxEventsJob
 * relays this outbox row.
 *
 * Deliberately never throws: this must never block an order from being
 * created, so any unexpected failure is logged and swallowed.
 */
class OrderObserver
{
    use RecordsOutboxEvents;

    public function created(Order $order): void
    {
        try {
            $this->recordOutboxEvent(new OrderAwarded(
                orderId: $order->id,
                countryCode: $this->destinationCountryCode($order),
            ));
        } catch (Throwable $e) {
            Log::error('OrderObserver: failed to record OrderAwarded outbox event for order.', [
                'order_id' => $order->id ?? null,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The order's destination country. Order carries destination_country_code
     * directly (set from the RFQ/shipping details at award time); that is the
     * real destination signal and is used first. If it is ever blank, we fall
     * back to the buyer's country snapshot on the order (buyer_country_code)
     * as the best available proxy for where the goods are headed.
     */
    protected function destinationCountryCode(Order $order): ?string
    {
        $destination = $order->destination_country_code ?? null;

        if (is_string($destination) && trim($destination) !== '') {
            return strtoupper(trim($destination));
        }

        $buyerCountry = $order->buyer_country_code ?? null;

        if (is_string($buyerCountry) && trim($buyerCountry) !== '') {
            return strtoupper(trim($buyerCountry));
        }

        return null;
    }
}
