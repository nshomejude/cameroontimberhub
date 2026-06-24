<?php

namespace App\Actions\Subscription;

use App\Events\PlanAssigned;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;

class AssignPlan
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function execute(Company $company, Plan $plan, ?User $actor = null, ?string $notes = null): Subscription
    {
        $subscription = $this->subscriptions->assign($company, $plan, $actor, $notes);

        PlanAssigned::dispatch($company, $plan, $actor);

        return $subscription;
    }
}
