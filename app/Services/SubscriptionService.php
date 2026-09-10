<?php

namespace App\Services;

use App\Domain\Commerce\Events\SubscriptionActivated;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Events\RecordsOutboxEvents;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manual plan assignment (no payment gateway). One active subscription per
 * company; assigning a plan cancels the prior active one and mirrors plan_id
 * onto the company for convenient feature-gate reads.
 */
class SubscriptionService
{
    use RecordsOutboxEvents;

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

    /**
     * Activate a subscription from a completed plan Payment (billing engine M1).
     *
     * Pull-model, no stored mandate (plan §7.5): a completed plan Payment is
     * the sole trigger. Idempotent by `payment_id` — a second call for the
     * same Payment (double webhook / the outbox event relayed twice) returns
     * the existing subscription untouched. Mirrors assign(): cancels the
     * prior active/trialing sub, mirrors plan_id onto the company, records a
     * SubscriptionActivated outbox event inside the same transaction, logs
     * activity. Price is snapshotted from what was actually paid, never the
     * plan's current list price.
     */
    public function activateFromPayment(Payment $payment): Subscription
    {
        return DB::transaction(function () use ($payment) {
            $existing = Subscription::query()->where('payment_id', $payment->getKey())->first();

            if ($existing !== null) {
                return $existing;
            }

            $plan = Plan::findOrFail($payment->plan_id);
            $company = Company::findOrFail($payment->company_id);

            $company->subscriptions()
                ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value])
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
                'billing_period' => $billingPeriod,
                'renews_at' => $renewsAt,
                // Term + price snapshot frozen at activation — the price the
                // customer actually paid, not $plan->price_amount (which may
                // change later).
                'price_amount' => $payment->amount,
                'price_currency' => $payment->currency,
                'payment_id' => $payment->getKey(),
                'provider_reference' => $payment->provider_reference,
                'assigned_by' => null,
            ]);

            $company->update(['plan_id' => $plan->getKey()]);

            $this->recordOutboxEvent(new SubscriptionActivated(
                subscriptionId: $subscription->getKey(),
                companyId: $subscription->company_id,
                planId: $subscription->plan_id,
            ));

            activity('subscription')->performedOn($company)->event('plan_assigned')
                ->withProperties(['plan' => $plan->slug, 'payment_id' => $payment->getKey()])
                ->log("Plan {$plan->slug} activated from payment #{$payment->getKey()}");

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
