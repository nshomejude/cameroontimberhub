<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A species a company handles, with its indicative catalogue terms — the
 * `company_species` pivot promoted to a first-class model so it can be
 * self-served (exporter panel) and can emit a `listed` PriceObservation on
 * save (docs/PRICE_DATA_STANDARD.md §5).
 */
class CompanySpecies extends Model
{
    use HasFactory;

    protected $table = 'company_species';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'min_order_m3' => 'decimal:2',
            'price_amount' => 'decimal:2',
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Standard §5: a catalogue "typical price" per species is a `listed`
        // signal. Never block the save — a price-intelligence side effect
        // must not be able to break a supplier editing their catalogue.
        static::saved(function (self $row): void {
            if ($row->price_amount === null || $row->price_currency === null) {
                return;
            }

            try {
                $row->loadMissing('species');

                PriceObservation::create([
                    'species_id' => $row->species_id,
                    'product_type' => $row->form,
                    'source' => 'listed',
                    'unit_price' => $row->price_amount,
                    'currency' => $row->price_currency,
                    'unit' => $row->unit ?: 'm3',
                    'basis' => $row->basis,
                    'region' => $row->region,
                    'quantity' => $row->min_order_m3,
                    'volume_band' => \App\Enums\PriceVolumeBand::forQuantity((float) ($row->min_order_m3 ?? 0))->value,
                    'observed_at' => now(),
                    'origin_type' => self::class,
                    'origin_id' => $row->getKey(),
                ]);
            } catch (Throwable $e) {
                Log::channel('errors')->error('CompanySpecies: failed to record listed PriceObservation.', [
                    'company_species_id' => $row->getKey(),
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }
}
