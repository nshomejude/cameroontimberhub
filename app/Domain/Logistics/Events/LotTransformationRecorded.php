<?php

namespace App\Domain\Logistics\Events;

use App\Support\Events\DomainEvent;

/**
 * A mass-balance transformation (App\Models\LotTransformation::recordFor())
 * was recorded: a processor turned some input TimberLot quantities into
 * output TimberLot quantities. Mirrors ShipmentCheckpointRecorded's exact
 * shape (same file's class doc) — one class per event, dispatched directly
 * via `event(new LotTransformationRecorded(...))`, reconstructable from a
 * stored outbox payload via `fromPayload()`.
 */
class LotTransformationRecorded implements DomainEvent
{
    public function __construct(
        public readonly int $lotTransformationId,
        public readonly int $processorCompanyId,
        public readonly string $transformationType,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            lotTransformationId: (int) $payload['lot_transformation_id'],
            processorCompanyId: (int) $payload['processor_company_id'],
            transformationType: (string) $payload['transformation_type'],
        );
    }

    public function aggregateType(): string
    {
        return 'LotTransformation';
    }

    public function aggregateId(): int|string
    {
        return $this->lotTransformationId;
    }

    public function eventType(): string
    {
        return 'lot_transformation.recorded';
    }

    public function payload(): array
    {
        return [
            'lot_transformation_id' => $this->lotTransformationId,
            'processor_company_id' => $this->processorCompanyId,
            'transformation_type' => $this->transformationType,
        ];
    }
}
