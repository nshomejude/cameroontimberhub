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
 * Sent to the order's supplier members when the requested carrier accepts a
 * booking request (ShipmentService::acceptBooking()). Mirrors
 * ShipmentAssignedNotification: mail always, database gated by
 * NotificationPreference.
 */
class ShipmentBookingAcceptedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'shipment_booking_accepted';

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
            ->subject(__('notifications.shipment_booking_accepted.subject', $this->replacements()))
            ->line(__('notifications.shipment_booking_accepted.line_1', $this->replacements()))
            ->action(__('notifications.shipment_booking_accepted.action'), $this->shipmentUrl());
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
            'title' => __('notifications.shipment_booking_accepted.subject', $this->replacements()),
            'body' => __('notifications.shipment_booking_accepted.line_1', $this->replacements()),
            'reference' => (string) $this->shipment->getKey(),
            'screen' => 'shipment',
            'url' => $this->shipmentUrl(),
        ];
    }

    /** @return array<string, string> */
    private function replacements(): array
    {
        $this->shipment->loadMissing(['order', 'carrierCompany']);

        return [
            'waybill' => (string) $this->shipment->waybill_number,
            'order' => (string) ($this->shipment->order?->reference_code ?? ''),
            'carrier' => (string) ($this->shipment->carrierCompany?->name ?? ''),
        ];
    }
}
