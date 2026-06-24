<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('companies.view')
            || $user->companies()->exists();
    }

    public function view(User $user, Lead $lead): bool
    {
        if ($user->can('companies.view')) {
            return true;
        }

        return $user->companies()->whereKey($lead->company_id)->exists();
    }

    public function updateStatus(User $user, Lead $lead): bool
    {
        return $user->companies()->whereKey($lead->company_id)->exists();
    }
}
