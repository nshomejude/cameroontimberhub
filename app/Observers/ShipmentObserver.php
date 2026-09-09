<?php

namespace App\Observers;

use App\Domain\Logistics\Events\ShipmentCheckpointRecorded;
use App\Enums\TrackingCheckpointStatus;
use App\Models\CheckpointUpdate;
use App\Models\Shipment;
use App\Support\Events\RecordsOutboxEvents;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wires shipment milestones into the transactional outbox (architecture
 * plan, Task 0.2).
 *
 * `Shipment` itself carries no status/location/checkpoint columns (see
 * app/Models/Shipment.php's class doc and the shipments migration) — a
 * shipment's "milestone" is recorded as a `CheckpointUpdate` row against it
 * as the polymorphic `trackable` (gap-plan 1.5.11), not a field change on
 * the Shipment row. So this observer, despite its name, is registered on
 * `CheckpointUpdate::created` and filters to checkpoints whose trackable is
 * a Shipment — that is the actual "shipment milestone changed" event in
 * this codebase. `CheckpointUpdate` itself is read-only for this task
 * (see task boundaries), so nothing there is touched.
 *
 * This records a ShipmentCheckpointRecorded outbox event for every such
 * checkpoint (the listener decides what, if anything, to do with the
 * status). The side effect this observer used to perform inline — mapping
 * status to a LotEventType and recording a LotEvent on every linked
 * TimberLot — has moved to App\Listeners\RecordLotEventOnShipmentCheckpoint,
 * a queued listener triggered asynchronously once App\Jobs\RelayOutboxEventsJob
 * relays this outbox row.
 *
 * Safety: every step is wrapped in try/catch. Any failure (missing
 * relation, DB error, etc.) is logged and swallowed — this must never block
 * a checkpoint from being recorded or a shipment from being tracked.
 */
class ShipmentObserver
{
    use RecordsOutboxEvents;

    public function created(CheckpointUpdate $checkpointUpdate): void
    {
        try {
            $this->handle($checkpointUpdate);
        } catch (Throwable $e) {
            Log::error('ShipmentObserver: failed to record ShipmentCheckpointRecorded outbox event', [
                'checkpoint_update_id' => $checkpointUpdate->id ?? null,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function handle(CheckpointUpdate $checkpointUpdate): void
    {
        if ($checkpointUpdate->trackable_type !== Shipment::class) {
            return;
        }

        $statusValue = $checkpointUpdate->status instanceof TrackingCheckpointStatus
            ? $checkpointUpdate->status->value
            : $checkpointUpdate->status;

        $this->recordOutboxEvent(new ShipmentCheckpointRecorded(
            checkpointUpdateId: $checkpointUpdate->id,
            shipmentId: $checkpointUpdate->trackable_id,
            status: $statusValue,
            location: $checkpointUpdate->location,
        ));
    }
}
