<?php

namespace App\Notifications;

use App\Models\Dispute;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the OTHER party when a dispute is opened on an order —
 * wired from `DisputeController::store()`, mirroring how `reply()` wires
 * `DisputeReplyNotification` (additive, at the controller layer).
 */
class DisputeOpenedNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'dispute_opened';

    public function __construct(public Dispute $dispute) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.dispute_opened.title'),
            'body' => __('notifications.push.dispute_opened.body', [
                'order' => $this->dispute->order?->reference_code,
            ]),
            'reference' => $this->dispute->order?->reference_code,
            'screen' => 'dispute',
        ];
    }
}
