<?php

namespace App\Models;

use App\Enums\CarbonRegistryStatus;
use App\Enums\ProductStatus;
use App\Support\CarbonProjectIdentifier;
use App\Support\GeoJsonPolygon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarbonProject extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'area_hectares' => 'decimal:2',
            'estimated_credits_per_year' => 'decimal:2',
            'status' => ProductStatus::class,
            'registry_status' => CarbonRegistryStatus::class,
        ];
    }

    /**
     * The project boundary (§2.6), stored as a GeoJSON Polygon in the jsonb
     * `boundary` column. Validated on write via App\Support\GeoJsonPolygon —
     * invalid structures never reach the DB. Mirrors TimberLot::originBoundary.
     */
    protected function boundary(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : json_decode($value, true),
            set: function (mixed $value) {
                if (is_string($value)) {
                    $value = json_decode($value, true);
                }

                if ($value !== null && ! GeoJsonPolygon::isValid($value)) {
                    throw new \InvalidArgumentException('boundary must be a valid GeoJSON Polygon.');
                }

                return $value === null ? null : json_encode($value);
            },
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function booted(): void
    {
        static::creating(function (CarbonProject $project): void {
            if (! filled($project->public_id)) {
                $project->public_id = CarbonProjectIdentifier::next();
            }

            if (! filled($project->verification_token)) {
                $project->verification_token = bin2hex(random_bytes(32));
            }

            if (! filled($project->registry_status)) {
                $project->registry_status = CarbonRegistryStatus::Draft;
            }
        });
    }

    /**
     * Move the project to $to if the registry transition table allows it.
     * Throws RuntimeException on an illegal transition — no silent no-op.
     */
    public function transitionTo(CarbonRegistryStatus $to): void
    {
        $from = $this->registry_status ?? CarbonRegistryStatus::Draft;

        if (! $from->canTransitionTo($to)) {
            throw new \RuntimeException("Illegal carbon registry transition: {$from->value} → {$to->value}.");
        }

        $this->registry_status = $to;
        $this->save();
    }
}
