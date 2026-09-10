<?php

namespace App\Listeners;

use App\Enums\PriceBasis;
use App\Enums\PriceVolumeBand;
use App\Models\PriceObservation;
use App\Models\Quote;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * docs/PRICE_DATA_STANDARD.md §5 — `source = 'quoted'` collector.
 *
 * There is no QuoteSubmitted domain event, so this is invoked directly from
 * App\Services\QuoteService::submit() after the transition to `submitted`,
 * inside a try/catch there — recording a price signal must never block a
 * supplier from submitting a quote. This class keeps the same defensive
 * shape regardless.
 */
class RecordQuotedPriceObservations
{
    public function record(Quote $quote): void
    {
        try {
            $this->process($quote);
        } catch (Throwable $e) {
            Log::channel('errors')->error('RecordQuotedPriceObservations: failed for quote.', [
                'listener' => self::class,
                'quote_id' => $quote->getKey(),
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
        }
    }

    private function process(Quote $quote): void
    {
        $alreadyRecorded = PriceObservation::query()
            ->where('source', 'quoted')
            ->where('origin_type', Quote::class)
            ->where('origin_id', $quote->getKey())
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $quote->loadMissing(['items.species', 'rfq']);

        $basis = PriceBasis::fromIncoterm($quote->incoterm)?->value;
        $region = $quote->rfq?->destination_country_code;
        $region = is_string($region) && trim($region) !== '' ? $region : null;
        $observedAt = $quote->submitted_at ?? now();

        foreach ($quote->items as $item) {
            if ($item->species_id === null) {
                continue;
            }

            $quantity = $item->quantity !== null ? (float) $item->quantity : null;

            PriceObservation::create([
                'species_id' => $item->species_id,
                'product_type' => $item->form?->value ?? $item->form,
                'source' => 'quoted',
                'unit_price' => $item->unit_price,
                'currency' => $quote->currency?->value ?? $quote->currency,
                'unit' => $item->unit?->value ?? $item->unit,
                'basis' => $basis,
                'region' => $region,
                'quantity' => $quantity,
                'volume_band' => PriceVolumeBand::forQuantity($quantity ?? 0.0)->value,
                'observed_at' => $observedAt,
                'origin_type' => Quote::class,
                'origin_id' => $quote->getKey(),
            ]);
        }
    }
}
