<?php

namespace App\Domain\Commerce\Events;

use App\Support\Events\DomainEvent;

/**
 * A Subscription became the company's active plan (App\Services\
 * SubscriptionService::assign() — the only place a Subscription is created;
 * assigning a new plan always cancels the prior active one and creates a
 * fresh Active row). High-value for "API as a product" webhooks — finance
 * and third-party billing/accounting integrations want to react the moment
 * a company's plan changes.
 */
class SubscriptionActivated implements DomainEvent
{
    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $companyId,
        public readonly int $planId,
    ) {}

    public static function fromPayload(array $payload): self
    {
        return new self(
            subscriptionId: (int) $payload['subscription_id'],
            companyId: (int) $payload['company_id'],
            planId: (int) $payload['plan_id'],
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
        return 'subscription.activated';
    }

    public function payload(): array
    {
        return [
            'subscription_id' => $this->subscriptionId,
            'company_id' => $this->companyId,
            'plan_id' => $this->planId,
        ];
    }
}
