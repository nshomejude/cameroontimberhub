<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\SupportTicket;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Shared shape for support-ticket notifications: database + Expo push,
 * each gated by NotificationPreference exactly like DisputeReplyNotification.
 * The app deep-links by `screen` + `reference`: owners land on 'support',
 * staff on 'staff_support'.
 */
abstract class SupportTicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SupportTicket $ticket, public string $body) {}

    abstract protected function type(): string;

    abstract protected function screen(): string;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (NotificationPreference::allows($notifiable, $this->type(), 'database')) {
            $channels[] = 'database';
        }

        if (NotificationPreference::allows($notifiable, $this->type(), 'push')) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type(),
            'title' => __('notifications.push.'.$this->type().'.title'),
            'body' => Str::limit($this->body, 140),
            'reference' => $this->ticket->reference,
            'screen' => $this->screen(),
        ];
    }
}
