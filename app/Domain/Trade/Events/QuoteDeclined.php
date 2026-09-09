<?php

namespace App\Domain\Trade\Events;

use App\Support\Events\DomainEvent;

/**
 * A Quote was declined by the buyer (see App\Services\QuoteService::decline()
 * and App\Domain\Trade\Commands\DeclineQuoteHandler, the seam that now
 * records this event transactionally alongside the state change).
 */
class QuoteDeclined implements DomainEvent
{
    public function __construct(
        public readonly int $quoteId,
        public readonly ?int $companyId,
        public readonly ?string $reason,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            quoteId: (int) $payload['quote_id'],
            companyId: isset($payload['company_id']) ? (int) $payload['company_id'] : null,
            reason: $payload['reason'] ?? null,
        );
    }

    public function aggregateType(): string
    {
        return 'Quote';
    }

    public function aggregateId(): int|string
    {
        return $this->quoteId;
    }

    public function eventType(): string
    {
        return 'quote.declined';
    }

    public function payload(): array
    {
        return [
            'quote_id' => $this->quoteId,
            'company_id' => $this->companyId,
            'reason' => $this->reason,
        ];
    }
}
