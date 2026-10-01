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
 * Sent to the order's supplier members when the requested carrier declines a
 * booking request (ShipmentService::declineBooking()). The carrier has
 * already been cleared from the shipment, so its name and the reason are
 * captured at construction. Mail always, database gated by
 * NotificationPreference.
 */
class ShipmentBookingDeclinedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'shipment_booking_declined';

    public function __construct(
        public Shipment $shipment,
        public string $carrierName,
        public ?string $reason = null,
    ) {}

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
        $mail = (new MailMessage)
            ->subject(__('notifications.shipment_booking_declined.subject', $this->replacements()))
            ->line(__('notifications.shipment_booking_declined.line_1', $this->replacements()));

        if (filled($this->reason)) {
            $mail->line(__('notifications.shipment_booking_declined.reason', ['reason' => $this->reason]));
        }

        return $mail->action(__('notifications.shipment_booking_declined.action'), $this->shipmentUrl());
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
            'title' => __('notifications.shipment_booking_declined.subject', $this->replacements()),
            'body' => __('notifications.shipment_booking_declined.line_1', $this->replacements()),
            'reason' => $this->reason,
            'reference' => (string) $this->shipment->getKey(),
            'screen' => 'shipment',
            'url' => $this->shipmentUrl(),
        ];
    }

    /** @return array<string, string> */
    private function replacements(): array
    {
        $this->shipment->loadMissing('order');

        return [
            'waybill' => (string) $this->shipment->waybill_number,
            'order' => (string) ($this->shipment->order?->reference_code ?? ''),
            'carrier' => $this->carrierName,
        ];
    }
}
