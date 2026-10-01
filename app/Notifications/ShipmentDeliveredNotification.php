<?php

namespace App\Notifications;

use App\Filament\Exporter\Resources\Shipments\ShipmentResource;
use App\Models\NotificationPreference;
use App\Models\Shipment;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a `delivered` checkpoint is recorded on a shipment
 * (ShipmentService::notifyCheckpointRecorded()), at the order's supplier
 * company users (`audience` = supplier) and at the buyer (`audience` =
 * buyer). It deliberately does NOT move the order status: the supplier still
 * marks the order delivered and the buyer confirms receipt through the
 * normal order lifecycle — this only tells them the goods have arrived.
 */
class ShipmentDeliveredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'shipment_delivered';

    public const AUDIENCE_SUPPLIER = 'supplier';

    public const AUDIENCE_BUYER = 'buyer';

    public function __construct(public Shipment $shipment, public string $audience) {}

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
        $key = "notifications.shipment_delivered.{$this->audience}";

        return (new MailMessage)
            ->subject(__("{$key}.subject", $this->replacements()))
            ->line(__("{$key}.line_1", $this->replacements()))
            ->action(__("{$key}.action"), $this->url())
            ->line(__("{$key}.line_2"));
    }

    public function url(): string
    {
        return $this->audience === self::AUDIENCE_BUYER
            ? route('account.orders')
            : ShipmentResource::getUrl('view', ['record' => $this->shipment], panel: 'exporter');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $key = "notifications.shipment_delivered.{$this->audience}";

        return [
            'type' => self::TYPE,
            'title' => __("{$key}.subject", $this->replacements()),
            'body' => __("{$key}.line_1", $this->replacements()),
            'reference' => (string) $this->shipment->order?->reference_code,
            'screen' => $this->audience === self::AUDIENCE_BUYER ? 'order' : 'shipment',
            'shipment_id' => $this->shipment->getKey(),
            'url' => $this->url(),
        ];
    }

    /** @return array<string, string> */
    private function replacements(): array
    {
        $this->shipment->loadMissing('order');

        return [
            'waybill' => (string) $this->shipment->waybill_number,
            'order' => (string) $this->shipment->order?->reference_code,
        ];
    }
}
