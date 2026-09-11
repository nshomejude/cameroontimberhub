<?php

namespace App\Services;

use App\Domain\Commerce\Events\SubscriptionActivated;
use App\Domain\Commerce\Events\SubscriptionLapsed;
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

            // Renewal detection (billing engine M6): a still-Active sub for the
            // SAME plan whose term has not yet elapsed means the customer paid
            // early / on time — the new term extends from the old `renews_at`
            // so they lose no days. A lapsed / past-due / trialing / different
            // -plan prior sub means "start a fresh term from now". Either way
            // the prior non-terminal sub is cancelled and a fresh Active row
            // is created (consistent with assign() and the prior-active path).
            $priorSamePlan = $company->subscriptions()
                ->where('plan_id', $plan->getKey())
                ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value, SubscriptionStatus::PastDue->value])
                ->orderByDesc('id')
                ->first();

            $company->subscriptions()
                ->whereIn('status', [
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::Trialing->value,
                    SubscriptionStatus::PastDue->value,
                ])
                ->update(['status' => SubscriptionStatus::Cancelled->value, 'cancelled_at' => now()]);

            $startsAt = now();
            $billingPeriod = $plan->billing_period;
            $termBase = ($priorSamePlan
                && $priorSamePlan->status === SubscriptionStatus::Active
                && $priorSamePlan->renews_at?->isFuture())
                ? $priorSamePlan->renews_at->copy()
                : $startsAt->copy();
            $renewsAt = match ($billingPeriod) {
                'yearly' => $termBase->addYear(),
                default => $termBase->addMonth(),
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

    /**
     * Start an opt-in free trial (billing engine M6, §7.5).
     *
     * Pull-model: full plan entitlements for `plan.trial_days` days, NO
     * pre-authorisation, `payment_id = null`. Converts when the customer pays
     * before `trial_ends_at` (normal checkout → activateFromPayment); if they
     * do not, `subscriptions:process-renewals` lapses it to the segment Free
     * plan. Guards: the plan must offer a trial; ONE trial per company for its
     * whole lifetime (any subscription row that ever had `trial_ends_at` set);
     * the company must not already be on a paid plan.
     */
    public function startTrial(Company $company, Plan $plan, ?User $actor = null): Subscription
    {
        if (! $plan->hasTrial()) {
            throw new RuntimeException('This plan does not offer a free trial.');
        }

        if ($company->subscriptions()->whereNotNull('trial_ends_at')->exists()) {
            throw new RuntimeException('This company has already used its one free trial.');
        }

        $current = $company->currentSubscription;
        if ($current !== null && $current->entitled() && ! ($current->plan?->isFree() ?? false)) {
            throw new RuntimeException('This company is already on a paid plan.');
        }

        return DB::transaction(function () use ($company, $plan, $actor) {
            $company->subscriptions()
                ->whereIn('status', [
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::Trialing->value,
                    SubscriptionStatus::PastDue->value,
                ])
                ->update(['status' => SubscriptionStatus::Cancelled->value, 'cancelled_at' => now()]);

            $startsAt = now();
            $trialEndsAt = $startsAt->copy()->addDays((int) $plan->trial_days);

            $subscription = $company->subscriptions()->create([
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Trialing,
                'starts_at' => $startsAt,
                'billing_period' => $plan->billing_period,
                'trial_ends_at' => $trialEndsAt,
                // The renewal job treats trial expiry off `trial_ends_at`; keep
                // `renews_at` aligned so admin views read sensibly.
                'renews_at' => $trialEndsAt,
                // The amount that will be due when the trial converts — a price
                // snapshot, exactly like a paid term.
                'price_amount' => $plan->price_amount,
                'price_currency' => $plan->price_currency,
                'payment_id' => null,
                'assigned_by' => $actor?->getKey(),
            ]);

            $company->update(['plan_id' => $plan->getKey()]);

            activity('subscription')->performedOn($company)->causedBy($actor)->event('trial_started')
                ->withProperties(['plan' => $plan->slug])->log("Free trial started for {$plan->slug}");

            return $subscription;
        });
    }

    /**
     * Ensure the company sits on an active Free subscription for its segment
     * (billing engine M6, §7.5) — the single DRY target for "lapsed to Free",
     * used by the renewal job after it Expires a lapsed paid sub, and safe to
     * call when the company is already on Free (idempotent: returns the
     * existing row, emits nothing).
     *
     * Mirrors the path CompanyObserver uses for a brand-new company. A Free
     * subscription is non-expiring: `renews_at` stays null so the renewal job
     * never touches it.
     */
    public function lapseToFree(Company $company, ?int $previousPlanId = null): ?Subscription
    {
        return DB::transaction(function () use ($company, $previousPlanId) {
            $segment = $company->currentSubscription?->plan?->segment ?? $company->plan?->segment;

            $freePlan = ($segment
                ? Plan::query()->forSegment($segment)->where('price_amount', 0)->orderBy('sort_order')->first()
                : null)
                ?? Plan::query()->where('slug', 'free')->first();

            if ($freePlan === null) {
                return null;
            }

            $existing = $company->subscriptions()
                ->where('status', SubscriptionStatus::Active->value)
                ->where('plan_id', $freePlan->getKey())
                ->orderByDesc('id')
                ->first();

            if ($existing !== null) {
                $company->update(['plan_id' => $freePlan->getKey()]);

                return $existing;
            }

            $company->subscriptions()
                ->whereIn('status', [
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::Trialing->value,
                    SubscriptionStatus::PastDue->value,
                ])
                ->update(['status' => SubscriptionStatus::Cancelled->value, 'cancelled_at' => now()]);

            $subscription = $company->subscriptions()->create([
                'plan_id' => $freePlan->getKey(),
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'billing_period' => $freePlan->billing_period,
                'renews_at' => null,
                'price_amount' => 0,
                'price_currency' => $freePlan->price_currency,
                'assigned_by' => null,
            ]);

            $company->update(['plan_id' => $freePlan->getKey()]);

            $this->recordOutboxEvent(new SubscriptionLapsed(
                subscriptionId: $subscription->getKey(),
                companyId: $company->getKey(),
                planId: $freePlan->getKey(),
                previousPlanId: $previousPlanId,
            ));

            activity('subscription')->performedOn($company)->event('lapsed_to_free')
                ->withProperties(['plan' => $freePlan->slug, 'previous_plan_id' => $previousPlanId])
                ->log("Subscription lapsed to Free plan {$freePlan->slug}");

            return $subscription;
        });
    }
}
