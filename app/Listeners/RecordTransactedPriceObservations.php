<?php

namespace App\Listeners;

use App\Domain\Trade\Events\OrderAwarded;
use App\Enums\PriceBasis;
use App\Enums\PriceVolumeBand;
use App\Models\Order;
use App\Models\PriceObservation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * docs/PRICE_DATA_STANDARD.md §5 — `source = 'transacted'` collector, the
 * highest-weight price signal. Queued off OrderAwarded (relayed from the
 * outbox), mirroring App\Listeners\OpenComplianceCaseOnOrderAwarded.
 *
 * Only line items that carry a real `species_id` produce an observation —
 * a freetext-only line is skipped rather than fabricating a species link.
 * Never throws out of handle(): a price side effect must never fail the
 * outbox relay.
 */
class RecordTransactedPriceObservations implements ShouldQueue
{
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(OrderAwarded $event): void
    {
        try {
            $this->process($event);
        } catch (Throwable $e) {
            Log::channel('errors')->error('RecordTransactedPriceObservations: failed for order.', [
                'listener' => self::class,
                'order_id' => $event->orderId,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
        }
    }

    public function failed(OrderAwarded $event, ?Throwable $e): void
    {
        Log::channel('errors')->error('RecordTransactedPriceObservations failed permanently.', [
            'listener' => self::class,
            'order_id' => $event->orderId,
            'exception' => $e?->getMessage(),
            'exception_class' => $e ? $e::class : null,
        ]);
    }

    private function process(OrderAwarded $event): void
    {
        $order = Order::query()->with('items.species')->find($event->orderId);

        if ($order === null) {
            return;
        }

        // Idempotent: a re-delivered OrderAwarded (queue retry, or the event
        // reaching more than one registered listener path) must not double
        // the observations for this order.
        $alreadyRecorded = PriceObservation::query()
            ->where('source', 'transacted')
            ->where('origin_type', Order::class)
            ->where('origin_id', $order->getKey())
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $basis = PriceBasis::fromIncoterm($order->incoterm)?->value;
        $region = is_string($order->destination_country_code) && trim($order->destination_country_code) !== ''
            ? $order->destination_country_code
            : null;
        $observedAt = $order->awarded_at ?? $order->created_at ?? now();

        foreach ($order->items as $item) {
            if ($item->species_id === null) {
                continue;
            }

            $quantity = $item->quantity !== null ? (float) $item->quantity : null;

            PriceObservation::create([
                'species_id' => $item->species_id,
                'product_type' => $item->form,
                'source' => 'transacted',
                'unit_price' => $item->unit_price,
                'currency' => $order->currency?->value ?? $order->currency,
                'unit' => $item->unit,
                'basis' => $basis,
                'region' => $region,
                'quantity' => $quantity,
                'volume_band' => PriceVolumeBand::forQuantity($quantity ?? 0.0)->value,
                'observed_at' => $observedAt,
                'origin_type' => Order::class,
                'origin_id' => $order->getKey(),
            ]);
        }
    }
}
