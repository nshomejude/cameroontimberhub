<?php

namespace App\Notifications;

use App\Filament\Exporter\Pages\Messages as ExporterMessages;
use App\Models\Message;
use App\Models\NotificationPreference;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Fired when a new plain-text message posts to a conversation —
 * `MessagingService::post()` is the trigger. Sent to the OTHER
 * participant(s) only; never to the sender.
 *
 * database/push fire once per message. The `mail` channel is COALESCED: at
 * most one email per conversation per recipient per MAIL_COALESCE_MINUTES,
 * claimed atomically with Cache::add() when the channels are resolved, so a
 * burst of chat messages produces one "you have a new message" email that
 * deep-links into the thread rather than an inbox flood. Mail also respects
 * the recipient's NotificationPreference (`channels.email` and the
 * `message_received` type).
 */
class MessageReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'message_received';

    public const MAIL_COALESCE_MINUTES = 30;

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

        if (filled($notifiable->email ?? null)
            && NotificationPreference::allows($notifiable, self::TYPE, 'mail')
            && Cache::add(self::coalesceKey($this->message->conversation_id, $notifiable->getKey()), true, now()->addMinutes(self::MAIL_COALESCE_MINUTES))) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public static function coalesceKey(int|string $conversationId, int|string $userId): string
    {
        return "notify:message-mail:{$conversationId}:{$userId}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $senderName = $this->message->sender?->name ?? __('notifications.message_received_mail.someone');

        return (new MailMessage)
            ->subject(__('notifications.message_received_mail.subject', ['sender' => $senderName]))
            ->line(__('notifications.message_received_mail.line_1', ['sender' => $senderName]))
            ->line('“'.Str::limit((string) $this->message->body, 280).'”')
            ->action(__('notifications.message_received_mail.action'), $this->conversationUrl($notifiable))
            ->line(__('notifications.message_received_mail.line_2', ['minutes' => self::MAIL_COALESCE_MINUTES]));
    }

    /**
     * Buyer side (the conversation's owner) reads threads under /account; the
     * supplier side reads them in the exporter panel's Messages page.
     */
    public function conversationUrl(object $notifiable): string
    {
        $conversation = $this->message->conversation;

        if ($conversation && (int) $conversation->user_id === (int) $notifiable->getKey()) {
            return route('account.messages.show', $conversation);
        }

        return ExporterMessages::getUrl(['conversation' => $this->message->conversation_id], panel: 'exporter');
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
