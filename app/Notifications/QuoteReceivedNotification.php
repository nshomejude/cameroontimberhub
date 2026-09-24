<?php

namespace App\Notifications;

use App\Models\NotificationPreference;
use App\Models\Quote;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the buyer when a supplier submits a quote on their RFQ —
 * `QuoteService::submit()` is the trigger. `type`/`reference` mirror the
 * `quote_received` pairing `DashboardController::activity()` already uses,
 * so the mobile client can route this notification with the same
 * `GET /quotes/{reference}` lookup the dashboard activity feed relies on.
 */
class QuoteReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'quote_received';

    public function __construct(public Quote $quote) {}

    /**
     * Sending is queued (ShouldQueue) so a slow Expo call never blocks the
     * request that triggered it. Channels are computed per-notifiable from
     * `NotificationPreference`: a `types.quote_received: false` user gets no
     * database row at all; a `channels.push: false` user still gets the
     * database row but no push.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (NotificationPreference::allows($notifiable, self::TYPE, 'database')) {
            $channels[] = 'database';
        }

        if (NotificationPreference::allows($notifiable, self::TYPE, 'push')) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $supplierName = $this->quote->company?->name ?? 'A supplier';

        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.quote_received.title'),
            'body' => __('notifications.push.quote_received.body', [
                'supplier' => $supplierName,
                'rfq' => $this->quote->rfq?->reference_code,
            ]),
            'reference' => $this->quote->reference_code,
            'screen' => 'quote',
        ];
    }
}
