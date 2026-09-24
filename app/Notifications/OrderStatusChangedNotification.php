<?php

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Fired when an order's status actually transitions — wired from
 * `OrderLifecycleService::advance()` (confirm/startProduction/ship/deliver)
 * and `OrderLifecycleService::complete()`. `type`/`reference` mirror the
 * `order_status_changed` pairing `DashboardController::activity()` uses.
 */
class OrderStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Order $order,
        public OrderStatus $from,
        public OrderStatus $to,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_status_changed',
            'title' => 'Order status updated',
            'body' => "Order {$this->order->reference_code} is now {$this->to->label()}.",
            'reference' => $this->order->reference_code,
            'screen' => 'order',
            'from_status' => $this->from->value,
            'to_status' => $this->to->value,
        ];
    }
}
