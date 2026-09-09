<?php

namespace App\Domain\Identity\Events;

use App\Support\Events\DomainEvent;

/**
 * A company's verification reached the terminal CompanyStatus::Verified
 * stage (see VerificationService::approve(), wrapped by
 * App\Domain\Identity\Commands\ApproveVerificationHandler). Genuinely useful
 * for external webhook subscribers — e.g. a partner integration wanting to
 * know when a supplier gets verified status, or the buyer mobile app wanting
 * a push notification (architecture plan, Phase 4: Identity & Access).
 */
class CompanyVerified implements DomainEvent
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $verificationRequestId,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            companyId: (int) $payload['company_id'],
            verificationRequestId: (int) $payload['verification_request_id'],
        );
    }

    public function aggregateType(): string
    {
        return 'Company';
    }

    public function aggregateId(): int|string
    {
        return $this->companyId;
    }

    public function eventType(): string
    {
        return 'company.verified';
    }

    public function payload(): array
    {
        return [
            'company_id' => $this->companyId,
            'verification_request_id' => $this->verificationRequestId,
        ];
    }
}
