<?php

namespace App\Domain\Compliance\Events;

use App\Support\Events\DomainEvent;

/**
 * A Dispute was opened against an Order (blueprint §64). Carries both
 * potential owning companies directly on the payload — raised_by_company_id
 * (null when the raiser is the buyer, who has no company on the order) and
 * respondent_company_id (null when the respondent is the buyer user) —
 * mirroring how QuoteDeclined/QuoteWithdrawn carry company_id directly
 * rather than making RelayOutboxEventsJob look it up. A Dispute has (up to)
 * two owning companies, unlike every other domain event so far, so both are
 * notified independently by RelayOutboxEventsJob::deliverWebhooksFor() if
 * they each have an active subscription.
 */
class DisputeOpened implements DomainEvent
{
    public function __construct(
        public readonly int $disputeId,
        public readonly int $orderId,
        public readonly ?int $raisedByCompanyId,
        public readonly ?int $respondentCompanyId,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            disputeId: (int) $payload['dispute_id'],
            orderId: (int) $payload['order_id'],
            raisedByCompanyId: isset($payload['raised_by_company_id']) ? (int) $payload['raised_by_company_id'] : null,
            respondentCompanyId: isset($payload['respondent_company_id']) ? (int) $payload['respondent_company_id'] : null,
        );
    }

    public function aggregateType(): string
    {
        return 'Dispute';
    }

    public function aggregateId(): int|string
    {
        return $this->disputeId;
    }

    public function eventType(): string
    {
        return 'dispute.opened';
    }

    public function payload(): array
    {
        return [
            'dispute_id' => $this->disputeId,
            'order_id' => $this->orderId,
            'raised_by_company_id' => $this->raisedByCompanyId,
            'respondent_company_id' => $this->respondentCompanyId,
        ];
    }
}
