<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Fired when a new plain-text message posts to a conversation —
 * `MessagingService::post()` is the trigger. Sent to the OTHER
 * participant(s) only; never to the sender.
 */
class MessageReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(public Message $message) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $senderName = $this->message->sender?->name ?? 'Someone';

        return [
            'type' => 'message_received',
            'title' => "New message from {$senderName}",
            'body' => Str::limit((string) $this->message->body, 140),
            'reference' => (string) $this->message->conversation_id,
            'screen' => 'conversation',
        ];
    }
}
