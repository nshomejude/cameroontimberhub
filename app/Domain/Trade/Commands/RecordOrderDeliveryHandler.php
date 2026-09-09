<?php

namespace App\Domain\Trade\Commands;

use App\Domain\Trade\Events\OrderDelivered;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;
use App\Support\Events\RecordsOutboxEvents;

/**
 * Thin seam over the existing delivered-transition logic. All the actual
 * state-machine/transition rules live in OrderService::deliver() (and its
 * shared ::transition() — see OrderService::TRANSITIONS) — this handler is
 * not a rewrite, it just gives that behaviour a Command/Bus entry point and
 * records the OrderDelivered domain event to the outbox in the same
 * transaction as the state change (CommandBus::dispatch() wraps the whole
 * handle() call in DB::transaction()).
 */
final class RecordOrderDeliveryHandler implements HandlesCommand
{
    use RecordsOutboxEvents;

    public function __construct(private readonly OrderService $orders) {}

    public function handle(Command $command): Order
    {
        /** @var RecordOrderDeliveryCommand $command */
        $order = Order::findOrFail($command->orderId);

        $actor = $command->actingUserId ? User::find($command->actingUserId) : null;

        $order = $this->orders->deliver($order, $actor);

        $this->recordOutboxEvent(new OrderDelivered(orderId: $order->getKey()));

        return $order;
    }
}
