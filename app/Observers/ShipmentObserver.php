<?php

namespace App\Observers;

use App\Enums\LotEventType;
use App\Enums\TrackingCheckpointStatus;
use App\Models\CheckpointUpdate;
use App\Models\Shipment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wires shipment milestones into each carried TimberLot's traceability
 * event ledger (blueprint §10).
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
 * Only the two `TrackingCheckpointStatus` values with an unambiguous
 * `LotEventType` counterpart are mapped:
 *   - Dispatched -> TransportDispatched
 *   - Delivered  -> Delivered
 * InTransit/Delayed have no corresponding blueprint lot-event type and are
 * intentionally left unmapped (no-op) rather than guessed at.
 *
 * Safety: every step is wrapped in try/catch. Any failure (missing
 * relation, DB error, hash-chain conflict, etc.) is logged and swallowed —
 * this must never block a checkpoint from being recorded or a shipment
 * from being tracked.
 */
class ShipmentObserver
{
    private const STATUS_TO_LOT_EVENT = [
        TrackingCheckpointStatus::Dispatched->value => LotEventType::TransportDispatched,
        TrackingCheckpointStatus::Delivered->value => LotEventType::Delivered,
    ];

    public function created(CheckpointUpdate $checkpointUpdate): void
    {
        try {
            $this->handle($checkpointUpdate);
        } catch (Throwable $e) {
            Log::error('ShipmentObserver: failed to record lot events for checkpoint update', [
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

        $lotEventType = self::STATUS_TO_LOT_EVENT[$statusValue] ?? null;

        if (! $lotEventType) {
            return;
        }

        $shipment = $checkpointUpdate->trackable;

        if (! $shipment instanceof Shipment) {
            return;
        }

        $lots = $shipment->timberLots;

        if (! $lots || $lots->isEmpty()) {
            return;
        }

        foreach ($lots as $lot) {
            try {
                $lot->recordEvent($lotEventType, [
                    'location' => $checkpointUpdate->location,
                ]);
            } catch (Throwable $e) {
                Log::error('ShipmentObserver: failed to record lot event for a linked lot', [
                    'timber_lot_id' => $lot->id ?? null,
                    'checkpoint_update_id' => $checkpointUpdate->id ?? null,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
