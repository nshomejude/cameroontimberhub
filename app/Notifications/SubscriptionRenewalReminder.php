<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Billing engine M6 (§7.5): sent ~7 days before `renews_at` by
 * `subscriptions:process-renewals`, once per term. Pull-model — there is no
 * stored mandate, so the customer must pay again via the re-pay link.
 */
class SubscriptionRenewalReminder extends Notification implements ShouldQueue
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
        $date = optional($this->subscription->renews_at)->translatedFormat('d M Y');
        $url = $plan ? route('billing.checkout', $plan) : url('/pricing');

        return (new MailMessage)
            ->subject(__('notifications.subscription_renewal.subject', ['plan' => $plan?->name]))
            ->line(__('notifications.subscription_renewal.line_1', ['plan' => $plan?->name, 'date' => $date, 'amount' => $amount]))
            ->line(__('notifications.subscription_renewal.line_2'))
            ->action(__('notifications.subscription_renewal.action'), $url)
            ->line(__('notifications.subscription_renewal.line_3'));
    }
}
