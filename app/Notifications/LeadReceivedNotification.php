<?php

namespace App\Notifications;

use App\Filament\Exporter\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Models\NotificationPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired at a supplier's company users when a buyer's "contact supplier"
 * inquiry is confirmed and becomes a lead — `LeadFlowService::
 * createFromInquiry()` is the trigger (only for a newly created lead).
 *
 * Mirrors RfqRoutedToExporter: mail always (this is the supplier's only
 * signal a buyer wrote to them), database gated by NotificationPreference.
 *
 * Deliberately NOT gated on the `leads_receive` plan feature: a buyer who
 * picked this specific supplier and confirmed their email must reach them on
 * any plan, or "contact supplier" silently fails for every free listing.
 * `leads_receive` keeps gating admin RFQ routing (RfqTriageService::route).
 */
class LeadReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'lead_received';

    public function __construct(public Lead $lead) {}

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
        $name = $this->lead->buyer_name ?: $this->lead->buyer_email;

        return (new MailMessage)
            ->subject(__('notifications.lead_received.subject', ['name' => $name]))
            ->line(__('notifications.lead_received.line_1', ['name' => $name]))
            ->action(__('notifications.lead_received.action'), $this->leadUrl())
            ->line(__('notifications.lead_received.line_2'));
    }

    public function leadUrl(): string
    {
        return LeadResource::getUrl('edit', ['record' => $this->lead], panel: 'exporter');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $name = $this->lead->buyer_name ?: $this->lead->buyer_email;

        return [
            'type' => self::TYPE,
            'title' => __('notifications.lead_received.subject', ['name' => $name]),
            'body' => __('notifications.lead_received.line_1', ['name' => $name]),
            'reference' => (string) $this->lead->getKey(),
            'screen' => 'lead',
            'url' => $this->leadUrl(),
        ];
    }
}
