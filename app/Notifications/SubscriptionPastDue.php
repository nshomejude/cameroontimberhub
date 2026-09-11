<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Billing engine M6 (§7.5): sent when a term reaches `renews_at` unpaid. The
 * subscription is now `past_due` with a 7-day grace window — entitlements are
 * retained until `grace_until`, after which it lapses to the segment Free plan.
 */
class SubscriptionPastDue extends Notification implements ShouldQueue
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
        $amount = number_format((float) $this->subscription->price_amount).' '.$this->subscription->price_currency;
        $until = optional($this->subscription->grace_until)->translatedFormat('d M Y');
        $url = $plan ? route('billing.checkout', $plan) : url('/pricing');

        return (new MailMessage)
            ->subject(__('notifications.subscription_past_due.subject', ['plan' => $plan?->name]))
            ->line(__('notifications.subscription_past_due.line_1', ['plan' => $plan?->name, 'amount' => $amount]))
            ->line(__('notifications.subscription_past_due.line_2', ['date' => $until]))
            ->action(__('notifications.subscription_past_due.action'), $url)
            ->line(__('notifications.subscription_past_due.line_3'));
    }
}
