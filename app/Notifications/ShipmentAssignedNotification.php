<?php

namespace App\Notifications;

use App\Filament\Exporter\Resources\Shipments\ShipmentResource;
use App\Models\NotificationPreference;
use App\Models\Shipment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired at every user of a third-party logistics company when a supplier
 * assigns that company as a shipment's carrier (ShipmentService::
 * createFromOrder() / updateAssignment()). It is the carrier's only signal
 * that it has been booked: carrier selection is open to any
 * OrganisationType::Logistics company without a prior acceptance step
 * (owner decision pending), so the carrier must at least be told.
 *
 * Mirrors LeadReceivedNotification: mail always, database gated by
 * NotificationPreference.
 */
class ShipmentAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'shipment_assigned';

    public function __construct(public Shipment $shipment) {}

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
        return (new MailMessage)
            ->subject(__('notifications.shipment_assigned.subject', $this->replacements()))
            ->line(__('notifications.shipment_assigned.line_1', $this->replacements()))
            ->action(__('notifications.shipment_assigned.action'), $this->shipmentUrl())
            ->line(__('notifications.shipment_assigned.line_2'));
    }

    public function shipmentUrl(): string
    {
        return ShipmentResource::getUrl('view', ['record' => $this->shipment], panel: 'exporter');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.shipment_assigned.subject', $this->replacements()),
            'body' => __('notifications.shipment_assigned.line_1', $this->replacements()),
            'reference' => (string) $this->shipment->getKey(),
            'screen' => 'shipment',
            'url' => $this->shipmentUrl(),
        ];
    }

    /** @return array<string, string> */
    private function replacements(): array
    {
        $this->shipment->loadMissing('order.company');

        return [
            'waybill' => (string) $this->shipment->waybill_number,
            'supplier' => (string) ($this->shipment->order?->company?->name ?? ''),
            'origin' => (string) ($this->shipment->origin ?: '—'),
            'destination' => (string) ($this->shipment->destination ?: '—'),
        ];
    }
}
