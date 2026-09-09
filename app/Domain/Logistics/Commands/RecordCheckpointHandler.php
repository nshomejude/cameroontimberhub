<?php

namespace App\Domain\Logistics\Commands;

use App\Models\CheckpointUpdate;
use App\Services\CheckpointTracker;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;

/**
 * Thin seam over the existing, single shared checkpoint-creation path
 * (App\Services\CheckpointTracker::record() — see its class doc, blueprint
 * §45-46). All token issuance/reuse and `occurred_at` handling for
 * offline-sync ordering live there and are NOT duplicated here.
 *
 * No outbox event is recorded in this handler: when the trackable is a
 * Shipment, App\Observers\ShipmentObserver (registered on
 * CheckpointUpdate::created) already records the ShipmentCheckpointRecorded
 * outbox event synchronously as part of the same create() call — which, via
 * CommandBus::dispatch()'s surrounding DB::transaction(), is still the same
 * transaction as this handler's state change. Recording it again here would
 * duplicate that outbox row.
 */
final class RecordCheckpointHandler implements HandlesCommand
{
    public function __construct(private readonly CheckpointTracker $tracker) {}

    public function handle(Command $command): CheckpointUpdate
    {
        /** @var RecordCheckpointCommand $command */
        return $this->tracker->record($command->trackable, $command->data);
    }
}
