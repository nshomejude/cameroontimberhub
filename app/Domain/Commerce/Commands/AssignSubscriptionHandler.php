<?php

namespace App\Domain\Commerce\Commands;

use App\Domain\Commerce\Events\SubscriptionActivated;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\Bus\Command;
use App\Support\Bus\HandlesCommand;
use App\Support\Events\RecordsOutboxEvents;

/**
 * Thin seam over the existing plan-assignment logic. All the actual state
 * machine (cancel prior active subscription, create the new one, mirror
 * plan_id onto the company, write the activity log) lives in
 * SubscriptionService::assign() — this handler is not a rewrite, it just
 * gives that behaviour a Command/Bus entry point and records the
 * SubscriptionActivated domain event to the outbox inside the same
 * transaction CommandBus::dispatch() already opens.
 */
final class AssignSubscriptionHandler implements HandlesCommand
{
    use RecordsOutboxEvents;

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(Command $command): Subscription
    {
        /** @var AssignSubscriptionCommand $command */
        $company = Company::findOrFail($command->companyId);
        $plan = Plan::findOrFail($command->planId);
        $actor = $command->actingUserId ? User::find($command->actingUserId) : null;

        $subscription = $this->subscriptions->assign($company, $plan, $actor, $command->notes);

        $this->recordOutboxEvent(new SubscriptionActivated(
            subscriptionId: $subscription->getKey(),
            companyId: $subscription->company_id,
            planId: $subscription->plan_id,
        ));

        return $subscription;
    }
}
