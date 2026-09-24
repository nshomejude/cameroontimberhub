<?php

namespace App\Notifications;

use App\Models\Dispute;
use App\Models\NotificationPreference;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Fired when a reply posts to a dispute — wired from
 * `DisputeController::reply()` (NOT `DisputeService::reply()` itself, since
 * that file had an in-flight change from another agent when this was built;
 * see the controller for the note). Sent to the other party only.
 */
class DisputeReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'dispute_reply';

    public function __construct(public Dispute $dispute, public string $body) {}

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
            'title' => __('notifications.push.dispute_reply.title'),
            'body' => Str::limit($this->body, 140),
            'reference' => $this->dispute->order?->reference_code,
            'screen' => 'dispute',
        ];
    }
}
