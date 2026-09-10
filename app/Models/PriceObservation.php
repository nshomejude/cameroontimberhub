<?php

namespace App\Models;

use App\Enums\PriceBasis;
use App\Enums\PriceVolumeBand;
use App\Enums\RfqCurrency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * docs/PRICE_DATA_STANDARD.md §2 — a write-time derivative price row.
 *
 * Every field is copied from an existing commercial row at the moment of a
 * real event; `origin()` points back to that row (an Order or a Quote today).
 * This model is append-only in practice — nothing updates an observation.
 */
class PriceObservation extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'unit_price' => 'decimal:2',
            'quantity' => 'decimal:2',
            'currency' => RfqCurrency::class,
            'basis' => PriceBasis::class,
            'volume_band' => PriceVolumeBand::class,
        ];
    }

    public function origin(): MorphTo
    {
        return $this->morphTo();
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }
}
