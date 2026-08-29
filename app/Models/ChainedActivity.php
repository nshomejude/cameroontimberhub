<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity;

/**
 * Adds a tamper-evident hash chain to spatie/laravel-activitylog's Activity
 * model (gap-plan 0.5). Registered as the package's `activity_model` in
 * config/activitylog.php so every activity() call across the app -- RFQ
 * triage, subscription assignment, certificate lifecycle, and any future
 * consumer -- is chained automatically, with no per-call-site change needed.
 *
 * Chains GLOBALLY in insertion order (not per-subject) -- see the migration
 * comment for why. hash covers this row's own core fields plus the
 * immediately preceding row's hash, so altering, deleting, or reordering
 * any historical row breaks every hash after it, detectable by
 * `activitylog:verify-chain`.
 */
class ChainedActivity extends Activity
{
    protected static function booted(): void
    {
        static::creating(function (ChainedActivity $activity) {
            $previous = static::query()->orderByDesc('id')->first();
            $activity->prev_hash = $previous?->hash;

            $activity->hash = hash('sha256', json_encode([
                'log_name' => $activity->log_name,
                'description' => $activity->description,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'causer_type' => $activity->causer_type,
                'causer_id' => $activity->causer_id,
                'properties' => $activity->properties?->toArray(),
                'prev_hash' => $activity->prev_hash,
            ], JSON_THROW_ON_ERROR));
        });
    }
}
