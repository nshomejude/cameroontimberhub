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
        $type = $this->document->documentType?->name ?? __('notifications.document_expiring.fallback_type');
        $company = $this->document->company?->name;

        $mail = (new MailMessage)->subject(__('notifications.document_expiring.subject', ['company' => $company]));

        if ($this->threshold === 'expired') {
            $mail->line(__('notifications.document_expiring.expired_line_1', ['type' => $type]))
                ->line(__('notifications.document_expiring.expired_line_2'));
        } else {
            $mail->line(__('notifications.document_expiring.expiring_line_1', [
                'type' => $type,
                'days' => $this->threshold,
                'date' => optional($this->document->expiry_date)->translatedFormat('d M Y'),
            ]))
                ->line(__('notifications.document_expiring.expiring_line_2'));
        }

        return $mail->action(__('notifications.document_expiring.action'), url('/dashboard'));
    }
}
