<?php

namespace App\Notifications;

use App\Models\QuoteCounterOffer;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the OTHER party when a counter-offer is opened or answered —
 * `ChatCommerceService::counter()` (new round) and `respondToCounter()`
 * (accept/decline) are the triggers.
 */
class CounterOfferNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'counter_offer';

    public function __construct(public QuoteCounterOffer $offer, public string $actorName) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $this->offer->loadMissing('quote');

        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.counter_offer.title'),
            'body' => __('notifications.push.counter_offer.body', [
                'party' => $this->actorName,
                'quote' => $this->offer->quote?->reference_code,
            ]),
            'reference' => $this->offer->quote?->reference_code,
            'screen' => 'quote',
        ];
    }
}
