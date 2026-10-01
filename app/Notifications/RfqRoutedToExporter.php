<?php

namespace App\Notifications;

use App\Filament\Exporter\Resources\Leads\LeadResource;
use App\Models\Company;
use App\Models\Lead;
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
        $mail = (new MailMessage)
            ->subject(__('notifications.rfq_routed_to_exporter.subject', ['reference' => $this->rfq->reference_code]))
            ->line(__('notifications.rfq_routed_to_exporter.line_1'));

        // Pending-verification suppliers receive requests but cannot respond yet.
        if (! $this->company->canRespondToBuyers()) {
            $mail->line(__(Company::VERIFICATION_REQUIRED_MESSAGE));
        }

        return $mail
            ->action(__('notifications.rfq_routed_to_exporter.action'), $this->leadUrl())
            ->line(__('notifications.rfq_routed_to_exporter.line_2', ['reference' => $this->rfq->reference_code]));
    }

    /**
     * Deep link to this RFQ's lead in the exporter panel (the lead is created
     * by LeadFlowService::createFromRouting() before this fires); falls back
     * to the leads inbox if it cannot be found.
     */
    public function leadUrl(): string
    {
        $lead = Lead::where('rfq_id', $this->rfq->getKey())
            ->where('company_id', $this->company->getKey())
            ->first();

        // A pending company cannot open the lead yet (LeadResource::canEdit()).
        return $lead && $this->company->canRespondToBuyers()
            ? LeadResource::getUrl('edit', ['record' => $lead], panel: 'exporter')
            : LeadResource::getUrl('index', panel: 'exporter');
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
            'can_respond' => $this->company->canRespondToBuyers(),
        ];
    }
}
