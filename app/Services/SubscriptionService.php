<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

            $subscription = $company->subscriptions()->create([
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'assigned_by' => $actor?->getKey(),
                'notes' => $notes,
            ]);

            $company->update(['plan_id' => $plan->getKey()]);

            activity('subscription')->performedOn($company)->causedBy($actor)->event('plan_assigned')
                ->withProperties(['plan' => $plan->slug])->log("Plan {$plan->slug} assigned");

            return $subscription;
        });
    }
}
