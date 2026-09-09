<?php

namespace App\Domain\Trade\Events;

use App\Support\Events\DomainEvent;

/**
 * An Order was marked delivered (see App\Services\OrderService::deliver(),
 * the only place `orders.status` moves to `delivered`). Recorded to the
 * outbox by App\Domain\Trade\Commands\RecordOrderDeliveryHandler, inside the
 * same DB transaction as the state change (the CommandBus wraps the whole
 * handler in DB::transaction() — see App\Support\Bus\CommandBus).
 */
class OrderDelivered implements DomainEvent
{
    public function __construct(
        public readonly int $orderId,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            orderId: (int) $payload['order_id'],
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
        return 'order.delivered';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
        ];
    }
}
