<?php

namespace App\Policies;

use App\Models\RfqCompany;
use App\Models\User;

class RfqCompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('rfqs.view') || $user->companies()->exists();
    }

    public function view(User $user, RfqCompany $routing): bool
    {
        if ($user->can('rfqs.view')) {
            return true;
        }

        return $user->companies()->whereKey($routing->company_id)->exists();
    }

    public function respond(User $user, RfqCompany $routing): bool
    {
        return $user->companies()->whereKey($routing->company_id)->exists();
    }

    public function update(User $user): bool
    {
        return $user->can('rfqs.route');
    }
}
