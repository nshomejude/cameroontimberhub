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
 * that it has been booked. Two flavours (owner decision): a direct
 * assignment (carrier_status=assigned, informational) or a booking request
 * (`$bookingRequest`, carrier_status=pending) whose copy asks the carrier to
 * accept or decline from the shipment page / API.
 *
 * Mirrors LeadReceivedNotification: mail always, database gated by
 * NotificationPreference.
 */
class ShipmentAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'shipment_assigned';

    public function __construct(public Shipment $shipment, public bool $bookingRequest = false) {}

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
        $key = $this->langKey();

        return (new MailMessage)
            ->subject(__("notifications.{$key}.subject", $this->replacements()))
            ->line(__("notifications.{$key}.line_1", $this->replacements()))
            ->action(__("notifications.{$key}.action"), $this->shipmentUrl())
            ->line(__("notifications.{$key}.line_2"));
    }

    private function langKey(): string
    {
        return $this->bookingRequest ? 'shipment_booking_requested' : 'shipment_assigned';
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
            'title' => __("notifications.{$this->langKey()}.subject", $this->replacements()),
            'body' => __("notifications.{$this->langKey()}.line_1", $this->replacements()),
            'booking_request' => $this->bookingRequest,
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
