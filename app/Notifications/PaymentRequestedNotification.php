<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the buyer when the supplier issues a payment request —
 * `OrderLifecycleService::requestPayment()` is the trigger.
 */
class PaymentRequestedNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'payment_requested';

    public function __construct(public Order $order) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.payment_requested.title'),
            'body' => __('notifications.push.payment_requested.body', [
                'order' => $this->order->reference_code,
            ]),
            'reference' => $this->order->reference_code,
            'screen' => 'order',
        ];
    }
}
