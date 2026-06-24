<?php

namespace App\Actions\Subscription;

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;

class CancelSubscription
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function execute(Subscription $subscription, ?User $actor = null): void
    {
        $this->subscriptions->cancel($subscription, $actor);
    }
}
