<?php

namespace App\Notifications;

use App\Models\CompanyDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DocumentExpiring extends Notification
{
    use Queueable;

    public function __construct(
        public CompanyDocument $document,
        public string $threshold,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type = $this->document->documentType?->name ?? 'compliance document';
        $company = $this->document->company?->name;

        $mail = (new MailMessage)->subject("Compliance document expiry — {$company}");

        if ($this->threshold === 'expired') {
            $mail->line("Your {$type} has expired.")
                ->line('Please upload a current document to keep your verified listing active.');
        } else {
            $mail->line("Your {$type} expires in {$this->threshold} days (on ".optional($this->document->expiry_date)->format('d M Y').').')
                ->line('Please renew it before it lapses to keep your verified listing.');
        }

        return $mail->action('Manage documents', url('/dashboard'));
    }
}
