<?php

namespace App\Domain\Compliance\Events;

use App\Support\Events\DomainEvent;

/**
 * An Inspection report was finalised (see App\Models\Inspection::finalise()
 * and App\Domain\Compliance\Commands\FinaliseInspectionHandler, the seam
 * that records this event transactionally alongside the state change). The
 * owning company isn't carried directly on the payload (unlike
 * DisputeOpened/QuoteDeclined) because it must be resolved via whichever of
 * timber_lot_id/order_id is set on the Inspection, matching
 * ComplianceCaseOpened's owner-resolution pattern — see
 * RelayOutboxEventsJob::resolveInspectionCompanyId().
 */
class InspectionFinalised implements DomainEvent
{
    public function __construct(
        public readonly int $inspectionId,
        public readonly ?int $timberLotId,
        public readonly ?int $orderId,
        public readonly ?string $result,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            inspectionId: (int) $payload['inspection_id'],
            timberLotId: isset($payload['timber_lot_id']) ? (int) $payload['timber_lot_id'] : null,
            orderId: isset($payload['order_id']) ? (int) $payload['order_id'] : null,
            result: $payload['result'] ?? null,
        );
    }

    public function aggregateType(): string
    {
        return 'Inspection';
    }

    public function aggregateId(): int|string
    {
        return $this->inspectionId;
    }

    public function eventType(): string
    {
        return 'inspection.finalised';
    }

    public function payload(): array
    {
        return [
            'inspection_id' => $this->inspectionId,
            'timber_lot_id' => $this->timberLotId,
            'order_id' => $this->orderId,
            'result' => $this->result,
        ];
    }
}
