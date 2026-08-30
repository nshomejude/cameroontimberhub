<?php

namespace App\Models;

use App\Enums\TrackingCheckpointStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One manually recorded checkpoint for any polymorphic `trackable` (gap-plan
 * 1.5.11). See database/migrations/2026_08_29_150010_create_checkpoint_updates_table.php
 * for the shape rationale and docs/superpowers/plans/2026-08-29-checkpoint-tracking.md
 * for the Shipment-wiring follow-up.
 */
class CheckpointUpdate extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TrackingCheckpointStatus::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeForTrackable(Builder $query, Model $trackable): Builder
    {
        return $query->where('trackable_type', $trackable::class)->where('trackable_id', $trackable->getKey());
    }

    public function scopeForToken(Builder $query, string $token): Builder
    {
        return $query->where('tracking_token', $token);
    }
}
