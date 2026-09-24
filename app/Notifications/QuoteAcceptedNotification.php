<?php

namespace App\Notifications;

use App\Models\Quote;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the supplier's company users when a buyer accepts their quote —
 * `ChatCommerceService::acceptQuotation()` is the trigger.
 */
class QuoteAcceptedNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'quote_accepted';

    public function __construct(public Quote $quote) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.quote_accepted.title'),
            'body' => __('notifications.push.quote_accepted.body', [
                'rfq' => $this->quote->rfq?->reference_code,
            ]),
            'reference' => $this->quote->reference_code,
            'screen' => 'quote',
        ];
    }
}
