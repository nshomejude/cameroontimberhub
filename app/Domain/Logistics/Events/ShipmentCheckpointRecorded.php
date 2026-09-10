<?php

namespace App\Domain\Logistics\Events;

use App\Support\Events\DomainEvent;

/**
 * A CheckpointUpdate was recorded against a Shipment (see
 * App\Observers\ShipmentObserver's class doc for why "shipment milestone"
 * means a CheckpointUpdate row rather than a Shipment field change).
 * Formalizes what ShipmentObserver used to do entirely inline: mapping the
 * checkpoint's status to a LotEventType and recording a LotEvent on every
 * TimberLot linked to the shipment. That side effect now lives in
 * App\Listeners\RecordLotEventOnShipmentCheckpoint, a queued listener
 * triggered asynchronously via the outbox relay.
 */
class ShipmentCheckpointRecorded implements DomainEvent
{
    public function __construct(
        public readonly int $checkpointUpdateId,
        public readonly int $shipmentId,
        public readonly string $status,
        public readonly ?string $location,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            checkpointUpdateId: (int) $payload['checkpoint_update_id'],
            shipmentId: (int) $payload['shipment_id'],
            status: (string) $payload['status'],
            location: $payload['location'] ?? null,
        );
    }

    public function aggregateType(): string
    {
        return 'Shipment';
    }

    public function aggregateId(): int|string
    {
        return $this->shipmentId;
    }

    public function eventType(): string
    {
        return 'checkpoint.recorded';
    }

    public function payload(): array
    {
        return [
            'checkpoint_update_id' => $this->checkpointUpdateId,
            'shipment_id' => $this->shipmentId,
            'status' => $this->status,
            'location' => $this->location,
        ];
    }
}
