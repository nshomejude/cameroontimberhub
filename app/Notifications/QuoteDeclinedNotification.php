<?php

namespace App\Notifications;

use App\Models\Quote;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the supplier's company users when a buyer declines their quote —
 * `ChatCommerceService::declineQuotation()` is the trigger.
 */
class QuoteDeclinedNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'quote_declined';

    public function __construct(public Quote $quote) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.quote_declined.title'),
            'body' => __('notifications.push.quote_declined.body', [
                'quote' => $this->quote->reference_code,
            ]),
            'reference' => $this->quote->reference_code,
            'screen' => 'quote',
        ];
    }
}
