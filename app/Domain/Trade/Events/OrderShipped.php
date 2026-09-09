<?php

namespace App\Domain\Trade\Events;

use App\Support\Events\DomainEvent;

/**
 * An Order was marked shipped (see App\Services\OrderService::ship(), the
 * only place `orders.status` moves to `shipped`). Recorded to the outbox by
 * App\Domain\Trade\Commands\RecordOrderShipmentHandler, inside the same DB
 * transaction as the state change (the CommandBus wraps the whole handler in
 * DB::transaction() — see App\Support\Bus\CommandBus).
 */
class OrderShipped implements DomainEvent
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
        return 'order.shipped';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
        ];
    }
}
