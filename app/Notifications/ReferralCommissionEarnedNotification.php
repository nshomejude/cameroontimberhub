<?php

namespace App\Notifications;

use App\Models\ReferralEarning;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the referrer when a referred company's first subscription
 * payment creates a commission — `ReferralService::awardForPayment()`.
 */
class ReferralCommissionEarnedNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'referral_commission_earned';

    public function __construct(public ReferralEarning $earning) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.referral_commission_earned.title'),
            'body' => __('notifications.push.referral_commission_earned.body', [
                'amount' => $this->earning->amountLabel(),
            ]),
            'reference' => $this->earning->source_reference,
            'screen' => 'referral',
        ];
    }
}
