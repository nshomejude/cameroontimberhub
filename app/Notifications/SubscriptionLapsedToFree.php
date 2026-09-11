<?php

namespace App\Notifications;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Billing engine M6 (§7.5): the grace window ended unpaid — the paid
 * subscription is `expired` and the company is now on its segment's Free
 * plan. Re-subscribing at any time is a normal checkout.
 */
class SubscriptionLapsedToFree extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  Subscription  $subscription  the now-expired paid subscription */
    public function __construct(public Subscription $subscription, public ?Plan $freePlan = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plan = $this->subscription->plan;

        return (new MailMessage)
            ->subject(__('notifications.subscription_lapsed.subject'))
            ->line(__('notifications.subscription_lapsed.line_1', ['plan' => $plan?->name, 'free' => $this->freePlan?->name]))
            ->line(__('notifications.subscription_lapsed.line_2'))
            ->action(__('notifications.subscription_lapsed.action'), url('/pricing'));
    }
}
