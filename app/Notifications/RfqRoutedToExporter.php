<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\NotificationPreference;
use App\Models\Rfq;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Extended (this task) to also fire `database` + push through the same
 * notification-centre system as the other event types, rather than staying
 * a mail-only path — `SendRfqRoutingNotifications` now fires this ONE
 * notification and gets all three channels. `$company` was added to the
 * constructor: the job already passed it (`new RfqRoutedToExporter($rfq,
 * $company)`), it was just silently dropped before since the old
 * single-arg constructor happened to still accept the call (PHP does not
 * error on an extra positional arg to a method that ignores it) — so this
 * also fixes a latent bug rather than only adding channels.
 */
class RfqRoutedToExporter extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'rfq_routed';

    public function __construct(public Rfq $rfq, public Company $company) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (NotificationPreference::allows($notifiable, self::TYPE, 'database')) {
            $channels[] = 'database';
        }

        if (NotificationPreference::allows($notifiable, self::TYPE, 'push')) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.rfq_routed_to_exporter.subject', ['reference' => $this->rfq->reference_code]))
            ->line(__('notifications.rfq_routed_to_exporter.line_1'))
            ->action(__('notifications.rfq_routed_to_exporter.action'), url('/dashboard'))
            ->line(__('notifications.rfq_routed_to_exporter.line_2', ['reference' => $this->rfq->reference_code]));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.rfq_routed.title'),
            'body' => __('notifications.push.rfq_routed.body'),
            'reference' => $this->rfq->reference_code,
            'screen' => 'rfq',
        ];
    }
}
