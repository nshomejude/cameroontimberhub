<?php

namespace App\Notifications;

use App\Enums\ReferralPayoutStatus;
use App\Models\NotificationPreference;
use App\Models\ReferralPayout;
use App\Models\User;
use App\Notifications\Channels\ExpoPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a referrer their commission payout was paid, failed (fix your PayPal
 * email; it will be retried) or is unclaimed at PayPal (claim it) —
 * App\Services\Referrals\ReferralPayoutService. Mail always goes (money
 * event); the in-app entry and push are preference-gated like every sibling.
 */
class ReferralPayoutUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'referral_payout_updated';

    public function __construct(public ReferralPayout $payout) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (! $notifiable instanceof User) {
            return $channels;
        }

        if (NotificationPreference::allows($notifiable, self::TYPE, 'database')) {
            $channels[] = 'database';
        }

        if (NotificationPreference::allows($notifiable, self::TYPE, 'push')) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }

    private function outcome(): string
    {
        return match ($this->payout->status) {
            ReferralPayoutStatus::Succeeded => 'paid',
            ReferralPayoutStatus::Unclaimed => 'unclaimed',
            default => 'failed',
        };
    }

    /** @return array<string, string> */
    private function params(): array
    {
        $earning = $this->payout->earning;

        return [
            'amount' => $earning?->amountLabel() ?? '',
            'reference' => (string) $earning?->source_reference,
            'email' => (string) $this->payout->maskedReceiver(),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $key = 'notifications.push.referral_payout_'.$this->outcome();

        return [
            'type' => self::TYPE,
            'title' => __($key.'.title'),
            'body' => __($key.'.body', $this->params()),
            'reference' => $this->payout->earning?->source_reference,
            'payout_status' => $this->outcome(),
            'screen' => 'referral',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = 'notifications.push.referral_payout_'.$this->outcome();

        $mail = (new MailMessage)
            ->subject(__($key.'.title'))
            ->line(__($key.'.body', $this->params()));

        if ($this->outcome() !== 'paid') {
            $mail->action(__('notifications.push.referral_payout_action'), url('/account/settings#referral-payouts'));
        }

        return $mail;
    }
}
