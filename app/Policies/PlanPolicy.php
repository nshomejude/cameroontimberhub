<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;

class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('plans.manage') || $user->can('companies.view');
    }

    public function view(User $user, Plan $plan): bool
    {
        return $user->can('plans.manage') || $user->can('companies.view');
    }

    public function create(User $user): bool
    {
        return $user->can('plans.manage');
    }

    public function update(User $user, Plan $plan): bool
    {
        return $user->can('plans.manage');
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $user->hasRole('super_admin');
    }
}
