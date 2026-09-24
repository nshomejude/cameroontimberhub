<?php

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Models\NotificationPreference;
use App\Models\Order;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired when an order's status actually transitions — wired from
 * `OrderLifecycleService::advance()` (confirm/startProduction/ship/deliver)
 * and `OrderLifecycleService::complete()`. `type`/`reference` mirror the
 * `order_status_changed` pairing `DashboardController::activity()` uses.
 */
class OrderStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'order_status_changed';

    public function __construct(
        public Order $order,
        public OrderStatus $from,
        public OrderStatus $to,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (NotificationPreference::allows($notifiable, self::TYPE, 'database')) {
            $channels[] = 'database';
        }

        if (NotificationPreference::allows($notifiable, self::TYPE, 'push')) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.order_status_changed.title'),
            'body' => __('notifications.push.order_status_changed.body', [
                'order' => $this->order->reference_code,
                'status' => $this->to->label(),
            ]),
            'reference' => $this->order->reference_code,
            'screen' => 'order',
            'from_status' => $this->from->value,
            'to_status' => $this->to->value,
        ];
    }
}
