<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\Rfq;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells every member of a company an RFQ was routed to that the buyer has
 * withdrawn (cancelled) it, so nobody keeps working on a quote. Sent by
 * RfqCancellationService. `database` is gated by the same per-type
 * preference as every other notification-centre event; mail always goes,
 * like RfqRoutedToExporter — the withdrawal is the counterpart of that lead
 * email and must not be silently muted.
 */
class RfqWithdrawnNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'rfq_withdrawn';

    public function __construct(public Rfq $rfq) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (NotificationPreference::allows($notifiable, self::TYPE, 'database')) {
            $channels[] = 'database';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reference = $this->rfq->reference_code;

        return (new MailMessage)
            ->subject(__('notifications.rfq_withdrawn.subject', ['reference' => $reference]))
            ->line(__('notifications.rfq_withdrawn.line_1', ['reference' => $reference]))
            ->line(__('notifications.rfq_withdrawn.line_2'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.rfq_withdrawn.title'),
            'body' => __('notifications.rfq_withdrawn.body', ['reference' => $this->rfq->reference_code]),
            'reference' => $this->rfq->reference_code,
            'screen' => 'rfq',
        ];
    }
}
