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

    public function latestCheckpoint(): ?CheckpointUpdate
    {
        return $this->checkpointUpdates()->latest('created_at')->first();
    }
}
