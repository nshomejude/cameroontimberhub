<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\NotificationPreference;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Fired when a new plain-text message posts to a conversation —
 * `MessagingService::post()` is the trigger. Sent to the OTHER
 * participant(s) only; never to the sender.
 *
 * Not coalesced per conversation: this fires once per message, exactly as
 * it did before push/preferences were added. A burst of messages in one
 * conversation therefore produces one push/database row per message. Left
 * as documented existing behaviour / a follow-up rather than redesigned
 * here, per the task's own instruction not to touch this unless trivial.
 */
class MessageReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'message_received';

    public function __construct(public Message $message) {}

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
        $senderName = $this->message->sender?->name ?? 'Someone';

        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.message_received.title', ['sender' => $senderName]),
            'body' => Str::limit((string) $this->message->body, 140),
            'reference' => (string) $this->message->conversation_id,
            'screen' => 'conversation',
        ];
    }
}
