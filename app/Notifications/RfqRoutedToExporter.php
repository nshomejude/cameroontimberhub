<?php

namespace App\Notifications;

use App\Models\Rfq;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RfqRoutedToExporter extends Notification
{
    use Queueable;

    public function __construct(public Rfq $rfq) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.rfq_routed_to_exporter.subject', ['reference' => $this->rfq->reference_code]))
            ->line(__('notifications.rfq_routed_to_exporter.line_1'))
            ->action(__('notifications.rfq_routed_to_exporter.action'), url('/dashboard'))
            ->line(__('notifications.rfq_routed_to_exporter.line_2', ['reference' => $this->rfq->reference_code]));
    }
}
