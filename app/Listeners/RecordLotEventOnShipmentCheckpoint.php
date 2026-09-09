<?php

namespace App\Listeners;

use App\Domain\Logistics\Events\ShipmentCheckpointRecorded;
use App\Enums\LotEventType;
use App\Enums\TrackingCheckpointStatus;
use App\Models\Shipment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wires shipment milestones into each carried TimberLot's traceability
 * event ledger (blueprint §10) — formalized off ShipmentCheckpointRecorded.
 *
 * This is exactly what App\Observers\ShipmentObserver used to do inline and
 * synchronously. Only the two TrackingCheckpointStatus values with an
 * unambiguous LotEventType counterpart are mapped (Dispatched, Delivered);
 * everything else is a no-op.
 *
 * Queued (ShouldQueue), so this now runs asynchronously off the outbox
 * relay. Every step stays wrapped in try/catch, same as the Observer did:
 * a failure here must never surface as a failed queue job that blocks the
 * outbox relay from marking other events published.
 */
class RecordLotEventOnShipmentCheckpoint implements ShouldQueue
{
    private const STATUS_TO_LOT_EVENT = [
        TrackingCheckpointStatus::Dispatched->value => LotEventType::TransportDispatched,
        TrackingCheckpointStatus::Delivered->value => LotEventType::Delivered,
    ];

    public function handle(ShipmentCheckpointRecorded $event): void
    {
        try {
            $this->process($event);
        } catch (Throwable $e) {
            Log::error('RecordLotEventOnShipmentCheckpoint: failed to record lot events for checkpoint update', [
                'checkpoint_update_id' => $event->checkpointUpdateId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function process(ShipmentCheckpointRecorded $event): void
    {
        $lotEventType = self::STATUS_TO_LOT_EVENT[$event->status] ?? null;

        if (! $lotEventType) {
            return;
        }

        $shipment = Shipment::find($event->shipmentId);

        if (! $shipment) {
            return;
        }

        $lots = $shipment->timberLots;

        if (! $lots || $lots->isEmpty()) {
            return;
        }

        foreach ($lots as $lot) {
            try {
                $lot->recordEvent($lotEventType, [
                    'location' => $event->location,
                ]);
            } catch (Throwable $e) {
                Log::error('RecordLotEventOnShipmentCheckpoint: failed to record lot event for a linked lot', [
                    'timber_lot_id' => $lot->id ?? null,
                    'checkpoint_update_id' => $event->checkpointUpdateId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
