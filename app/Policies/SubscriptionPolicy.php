<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('plans.manage') || $user->can('companies.view');
    }

    public function view(User $user, Subscription $subscription): bool
    {
        if ($user->can('plans.manage')) {
            return true;
        }

        return $subscription->company->users()->whereKey($user->getKey())->exists();
    }

    public function assign(User $user): bool
    {
        return $user->can('plans.manage');
    }

    public function cancel(User $user, Subscription $subscription): bool
    {
        return $user->can('plans.manage');
    }

    public function delete(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
