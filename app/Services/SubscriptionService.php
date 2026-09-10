<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manual plan assignment (no payment gateway). One active subscription per
 * company; assigning a plan cancels the prior active one and mirrors plan_id
 * onto the company for convenient feature-gate reads.
 */
class SubscriptionService
{
    public function assign(Company $company, Plan $plan, ?User $actor = null, ?string $notes = null): Subscription
    {
        return DB::transaction(function () use ($company, $plan, $actor, $notes) {
            $company->subscriptions()
                ->where('status', SubscriptionStatus::Active->value)
                ->update(['status' => SubscriptionStatus::Cancelled->value, 'cancelled_at' => now()]);

            $startsAt = now();
            $billingPeriod = $plan->billing_period;
            $renewsAt = match ($billingPeriod) {
                'yearly' => $startsAt->copy()->addYear(),
                default => $startsAt->copy()->addMonth(),
            };

            $subscription = $company->subscriptions()->create([
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Active,
                'starts_at' => $startsAt,
                // Term + price snapshot (billing engine M3): frozen at activation
                // so a later plan price change never rewrites this subscription.
                'billing_period' => $billingPeriod,
                'renews_at' => $renewsAt,
                'price_amount' => $plan->price_amount,
                'price_currency' => $plan->price_currency,
                'assigned_by' => $actor?->getKey(),
                'notes' => $notes,
            ]);

            $company->update(['plan_id' => $plan->getKey()]);

            activity('subscription')->performedOn($company)->causedBy($actor)->event('plan_assigned')
                ->withProperties(['plan' => $plan->slug])->log("Plan {$plan->slug} assigned");

            return $subscription;
        });
    }

    public function cancel(Subscription $subscription, ?User $actor = null, ?string $reason = null): void
    {
        if ($subscription->status === SubscriptionStatus::Cancelled) {
            throw new RuntimeException('Subscription is already cancelled.');
        }

        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'notes' => $reason,
        ]);

        $company = $subscription->company;
        if ($company && $company->plan_id === $subscription->plan_id) {
            $company->update(['plan_id' => null]);
        }

        activity('subscription')->performedOn($subscription)->causedBy($actor)->event('cancelled')
            ->withProperties(['reason' => $reason])->log('Subscription cancelled');
    }
}
