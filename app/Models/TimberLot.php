<?php

namespace App\Models;

use App\Enums\TimberLotStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A structured Timber Lot (implementation blueprint §8) — the unit
 * everything downstream (traceability events, mass-balance transformations,
 * the Timber Passport) attaches to. Distinct from Product: a Product is a
 * public marketplace listing; a TimberLot is the underlying supply-chain
 * object a listing may (optionally) reference.
 */
class TimberLot extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TimberLotStatus::class,
            'quantity' => 'decimal:2',
            'volume_m3' => 'decimal:3',
            'available_quantity' => 'decimal:2',
            'reserved_quantity' => 'decimal:2',
            'origin_latitude' => 'decimal:6',
            'origin_longitude' => 'decimal:6',
            'harvest_period_start' => 'date',
            'harvest_period_end' => 'date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'lot_number';
    }

    protected static function booted(): void
    {
        static::creating(function (TimberLot $lot) {
            if (! $lot->lot_number) {
                $lot->lot_number = self::generateLotNumber();
            }
        });
    }

    /** "CTH-TIM-{year}-{6-digit sequence}", per the blueprint's own example format. */
    public static function generateLotNumber(): string
    {
        $year = now()->year;
        $count = self::query()->whereYear('created_at', $year)->count() + 1;

        return sprintf('CTH-TIM-%d-%06d', $year, $count);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', TimberLotStatus::Available->value);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
