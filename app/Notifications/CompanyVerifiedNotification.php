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
            ->subject('Your company has been verified — ' . $this->company->name)
            ->line('Congratulations! ' . $this->company->name . ' has been verified by the Cameroon Timber Hub team.')
            ->line('Your verified badge is now live on the public directory.')
            ->action('View your dashboard', url('/dashboard'))
            ->line('Documents reviewed by Cameroon Timber Hub based on information submitted by the company. Buyers should conduct final due diligence before any transaction.');
    }
}
