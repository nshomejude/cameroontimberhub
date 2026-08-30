<?php

namespace App\Models;

use App\Enums\LotEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A single append-only entry in a TimberLot's Traceability Event Ledger
 * (implementation blueprint §10). Hash-chained per lot, following
 * ChainedActivity's exact approach (see app/Models/ChainedActivity.php):
 * sha256 of this row's own core fields plus the previous row's hash.
 *
 * Append-only: update()/delete() are refused outright, since the blueprint
 * is explicit that this ledger must be tamper-evident, not just chained.
 */
class LotEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_type' => LotEventType::class,
            'occurred_at' => 'datetime',
            'documents' => 'array',
            'evidence' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LotEvent $event) {
            $previous = static::query()
                ->where('timber_lot_id', $event->timber_lot_id)
                ->orderByDesc('id')
                ->first();

            $event->previous_event_hash = $previous?->event_hash;

            // Normalize before hashing: an unset documents/evidence attribute
            // is null in-memory on create, but reloads from the database as
            // [] (the jsonb column's '{}' default) -- without this, the
            // freshly-created row and the same row re-fetched later would
            // hash to different values.
            $event->documents ??= [];
            $event->evidence ??= [];

            $event->event_hash = hash('sha256', json_encode([
                'timber_lot_id' => $event->timber_lot_id,
                'event_type' => $event->event_type instanceof LotEventType ? $event->event_type->value : $event->event_type,
                'actor_id' => $event->actor_id,
                // occurred_at is deliberately NOT part of the hashed payload,
                // matching ChainedActivity's own field list (which likewise
                // excludes its timestamp columns): this app's configured
                // timezone differs from the database connection's, so a
                // freshly-created Carbon instant and the same row reloaded
                // from Postgres can come back shifted by a whole offset,
                // which would make every row fail self-verification purely
                // from a timezone round trip rather than real tampering.
                'location' => $event->location,
                'quantity_before' => $event->quantity_before,
                'quantity_after' => $event->quantity_after,
                'documents' => $event->documents,
                'evidence' => $event->evidence,
                'notes' => $event->notes,
                'previous_event_hash' => $event->previous_event_hash,
            ], JSON_THROW_ON_ERROR));
        });

        static::updating(function () {
            throw new RuntimeException('LotEvent rows are append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException('LotEvent rows are append-only and cannot be deleted.');
        });
    }

    public function timberLot(): BelongsTo
    {
        return $this->belongsTo(TimberLot::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
