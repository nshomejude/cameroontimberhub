<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('companies.view');
    }

    public function view(User $user, Company $company): bool
    {
        return $user->can('companies.view')
            || $company->users()->whereKey($user->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('companies.manage');
    }

    public function update(User $user, Company $company): bool
    {
        if ($user->can('companies.manage')) {
            return true;
        }

        $pivot = $company->users()->whereKey($user->getKey())->first()?->pivot;

        return $pivot && in_array($pivot->role, ['owner', 'manager'], true);
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->can('companies.manage');
    }

    public function submit(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }

    public function verify(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'verification_officer']);
    }

    public function suspend(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    public function archive(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }
}
