<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Billing engine M6 (§7.5): an opt-in free trial reached `trial_ends_at`
 * without a payment — no surprise charge, the company simply drops to its
 * segment's Free plan.
 */
class TrialEndedUnpaid extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Subscription $subscription) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plan = $this->subscription->plan;
        $url = $plan ? route('billing.checkout', $plan) : url('/pricing');

        return (new MailMessage)
            ->subject(__('notifications.trial_ended.subject', ['plan' => $plan?->name]))
            ->line(__('notifications.trial_ended.line_1', ['plan' => $plan?->name]))
            ->line(__('notifications.trial_ended.line_2'))
            ->action(__('notifications.trial_ended.action'), $url);
    }
}
