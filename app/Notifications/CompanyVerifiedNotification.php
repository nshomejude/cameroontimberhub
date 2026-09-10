<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyVerifiedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Company $company) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.company_verified.subject', ['company' => $this->company->name]))
            ->line(__('notifications.company_verified.line_1', ['company' => $this->company->name]))
            ->line(__('notifications.company_verified.line_2'))
            ->action(__('notifications.company_verified.action'), url('/dashboard'))
            ->line(__('notifications.company_verified.line_3'));
    }
}
