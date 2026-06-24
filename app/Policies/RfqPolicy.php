<?php

namespace App\Policies;

use App\Models\Rfq;
use App\Models\User;

class RfqPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rfqs.view');
    }

    public function view(User $user, Rfq $rfq): bool
    {
        return $user->can('rfqs.view');
    }

    public function triage(User $user): bool
    {
        return $user->can('rfqs.triage');
    }

    public function route(User $user): bool
    {
        return $user->can('rfqs.route');
    }

    public function delete(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
