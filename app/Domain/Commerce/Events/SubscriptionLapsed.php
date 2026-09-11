<?php

namespace App\Domain\Commerce\Events;

use App\Support\Events\DomainEvent;

/**
 * A paid Subscription lapsed to the segment's Free plan (billing engine M6,
 * §7.5) — the customer did not pay by `renews_at` + grace, or an opt-in
 * trial ended unpaid. The mirror image of SubscriptionActivated: finance and
 * third-party billing/accounting integrations want to react the moment a
 * company drops off a paid plan. `planId` here is the Free plan the company
 * landed on; `previousPlanId` is the paid plan it left.
 */
class SubscriptionLapsed implements DomainEvent
{
    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $companyId,
        public readonly int $planId,
        public readonly ?int $previousPlanId = null,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            subscriptionId: (int) $payload['subscription_id'],
            companyId: (int) $payload['company_id'],
            planId: (int) $payload['plan_id'],
            previousPlanId: isset($payload['previous_plan_id']) ? (int) $payload['previous_plan_id'] : null,
        );
    }

    public function aggregateType(): string
    {
        return 'Subscription';
    }

    public function aggregateId(): int|string
    {
        return $this->subscriptionId;
    }

    public function eventType(): string
    {
        return 'subscription.lapsed';
    }

    public function payload(): array
    {
        return [
            'subscription_id' => $this->subscriptionId,
            'company_id' => $this->companyId,
            'plan_id' => $this->planId,
            'previous_plan_id' => $this->previousPlanId,
        ];
    }
}
