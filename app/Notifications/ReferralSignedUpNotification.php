<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Referrals\ReferralService;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the referrer when someone registers with their referral code —
 * `ReferralService::attachReferrer()` is the trigger.
 */
class ReferralSignedUpNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'referral_signed_up';

    public function __construct(public User $referred) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.referral_signed_up.title'),
            'body' => __('notifications.push.referral_signed_up.body', [
                'name' => ReferralService::maskName($this->referred->name),
            ]),
            'reference' => null,
            'screen' => 'referral',
        ];
    }
}
