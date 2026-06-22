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
            ->subject('New buyer lead — '.$this->rfq->reference_code)
            ->line('A verified buyer request has been routed to your company.')
            ->action('View in your dashboard', url('/dashboard'))
            ->line('Reference: '.$this->rfq->reference_code);
    }
}
