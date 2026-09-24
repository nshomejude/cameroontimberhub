<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the buyer when shipment facts are updated without a status move —
 * `OrderLifecycleService::updateTracking()` is the trigger. A `ship()` status
 * transition is covered separately by `OrderStatusChangedNotification`; this
 * class only fires for the tracking-only update path.
 */
class ShipmentUpdateNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'shipment_update';

    public function __construct(public Order $order) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.shipment_update.title'),
            'body' => __('notifications.push.shipment_update.body', [
                'order' => $this->order->reference_code,
            ]),
            'reference' => $this->order->reference_code,
            'screen' => 'order',
        ];
    }
}
