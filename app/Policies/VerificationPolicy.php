<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Verification;

class VerificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('verification.review');
    }

    public function view(User $user, Verification $verification): bool
    {
        return $user->can('verification.review');
    }

    public function review(User $user, Verification $verification): bool
    {
        return $user->can('verification.review');
    }

    public function create(User $user): bool
    {
        return $user->can('verification.review') || $user->can('products.manage');
    }
}
