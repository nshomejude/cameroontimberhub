<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VerificationBadge;

class VerificationBadgePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('verification.review') || $user->can('companies.view');
    }

    public function view(User $user, VerificationBadge $badge): bool
    {
        if ($user->can('verification.review')) {
            return true;
        }

        return $badge->company->users()->whereKey($user->getKey())->exists();
    }

    public function issue(User $user): bool
    {
        return $user->can('badges.issue');
    }

    public function revoke(User $user): bool
    {
        return $user->can('badges.revoke');
    }

    public function delete(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
