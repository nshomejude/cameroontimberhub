<?php

namespace App\Domain\Trade\Events;

use App\Support\Events\DomainEvent;

/**
 * An Order was awarded (created from an accepted Quote — see
 * App\Services\OrderService::createFromQuote(), the only place an Order
 * comes into existence). Formalizes what App\Observers\OrderObserver used
 * to do entirely inline: evaluating whether an active compliance rule
 * applies to the order's destination and, if so, opening a ComplianceCase.
 * That side effect now lives in App\Listeners\OpenComplianceCaseOnOrderAwarded,
 * a queued listener triggered asynchronously via the outbox relay.
 */
class OrderAwarded implements DomainEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly ?string $countryCode,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            orderId: (int) $payload['order_id'],
            countryCode: $payload['country_code'] ?? null,
        );
    }

    public function aggregateType(): string
    {
        return 'Order';
    }

    public function aggregateId(): int|string
    {
        return $this->orderId;
    }

    public function eventType(): string
    {
        return 'order.awarded';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'country_code' => $this->countryCode,
        ];
    }
}
