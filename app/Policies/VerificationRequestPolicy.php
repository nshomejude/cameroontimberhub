<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VerificationRequest;

class VerificationRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('verification.review') || $user->can('companies.view');
    }

    public function view(User $user, VerificationRequest $request): bool
    {
        if ($user->can('verification.review')) {
            return true;
        }

        return $request->company->users()->whereKey($user->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('companies.manage');
    }

    public function review(User $user): bool
    {
        return $user->can('verification.review');
    }

    public function delete(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
