<?php

namespace App\Domain\Compliance\Events;

use App\Support\Events\DomainEvent;

/**
 * A ComplianceCase was opened. Currently only raised from
 * App\Listeners\OpenComplianceCaseOnOrderAwarded (itself triggered by
 * OrderAwarded), but modeled as its own event/aggregate — not folded into
 * OrderAwarded's payload — because a ComplianceCase can, per the architecture
 * plan's bounded-context table, eventually be opened against other owner
 * types (TimberLot, Shipment) too. No listener is registered for it yet
 * (mirrors the existing "dispatched for future listeners" entries in
 * EventServiceProvider, e.g. DocumentApproved/BadgeIssued) — it exists so
 * downstream consumers (webhooks, audit, notifications) have a stable typed
 * event to subscribe to without touching this code again.
 */
class ComplianceCaseOpened implements DomainEvent
{
    public function __construct(
        public readonly int $complianceCaseId,
        public readonly string $ownerType,
        public readonly int $ownerId,
        public readonly string $countryCode,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            complianceCaseId: (int) $payload['compliance_case_id'],
            ownerType: (string) $payload['owner_type'],
            ownerId: (int) $payload['owner_id'],
            countryCode: (string) $payload['country_code'],
        );
    }

    public function aggregateType(): string
    {
        return 'ComplianceCase';
    }

    public function aggregateId(): int|string
    {
        return $this->complianceCaseId;
    }

    public function eventType(): string
    {
        return 'compliance.case_opened';
    }

    public function payload(): array
    {
        return [
            'compliance_case_id' => $this->complianceCaseId,
            'owner_type' => $this->ownerType,
            'owner_id' => $this->ownerId,
            'country_code' => $this->countryCode,
        ];
    }
}
