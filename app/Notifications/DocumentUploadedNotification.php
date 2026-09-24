<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the buyer when the supplier attaches order documents —
 * `OrderLifecycleService::attachDocuments()` is the trigger.
 */
class DocumentUploadedNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'document_uploaded';

    public function __construct(public Order $order) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.document_uploaded.title'),
            'body' => __('notifications.push.document_uploaded.body', [
                'order' => $this->order->reference_code,
            ]),
            'reference' => $this->order->reference_code,
            'screen' => 'order',
        ];
    }
}
