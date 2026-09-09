<?php

namespace App\Models\Concerns;

use App\Models\CheckpointUpdate;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model a polymorphic `checkpointUpdates` relation over the shared
 * CheckpointUpdate ledger (gap-plan 1.5.11). Add `use HasCheckpointUpdates;`
 * to any model that needs a manual checkpoint history — see
 * app/Models/CheckpointUpdate.php for the full contract. `Shipment`
 * (gap-plan 1.5.10) is the intended first real consumer; wiring it up is a
 * tracked follow-up, not done as part of this trait's introduction — see
 * docs/superpowers/plans/2026-08-29-checkpoint-tracking.md.
 */
trait HasCheckpointUpdates
{
    public function checkpointUpdates(): MorphMany
    {
        return $this->morphMany(CheckpointUpdate::class, 'trackable');
    }

    /**
     * The checkpoint that represents this trackable's real-world current
     * state. Ordered by `occurred_at` (the client-reported time the event
     * happened), NOT `created_at` (when the server received it) — see the
     * 2026_09_09_120000_add_occurred_at_to_checkpoint_updates_table
     * migration doc. This matters for offline field capture (blueprint
     * §45-46): a driver's checkpoints can sync out of order over patchy
     * connectivity, and ordering by receipt order rather than event order
     * could let a stale, late-arriving "dispatched" sync stomp on an
     * already-recorded "delivered" as the displayed current status.
     * `occurred_at` defaults to `created_at` for any row that doesn't set it
     * (CheckpointUpdate::booted()), so this is a no-op change in ordering
     * for every existing caller.
     */
    public function latestCheckpoint(): ?CheckpointUpdate
    {
        return $this->checkpointUpdates()->latest('occurred_at')->first();
    }
}
