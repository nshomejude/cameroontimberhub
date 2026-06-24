<?php

namespace App\Policies;

use App\Enums\CompanyUserRole;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\User;

class CompanyDocumentPolicy
{
    public function viewAny(User $user, ?Company $company = null): bool
    {
        if ($user->can('companies.view')) {
            return true;
        }

        return $company !== null
            && $company->users()->whereKey($user->getKey())->exists();
    }

    public function view(User $user, CompanyDocument $document): bool
    {
        return $user->can('companies.view')
            || $document->company->users()->whereKey($user->getKey())->exists();
    }

    public function create(User $user, ?Company $company = null): bool
    {
        if ($user->can('companies.manage')) {
            return true;
        }

        if ($company === null) {
            return $user->companies()->wherePivotIn('role', [CompanyUserRole::Owner->value, CompanyUserRole::Manager->value])->exists();
        }

        $pivot = $company->users()->whereKey($user->getKey())->first()?->pivot;

        return $pivot && in_array($pivot->role, [CompanyUserRole::Owner, CompanyUserRole::Manager], true);
    }

    public function approve(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'verification_officer']);
    }

    public function reject(User $user): bool
    {
        return $this->approve($user);
    }

    public function requestCorrection(User $user): bool
    {
        return $this->approve($user);
    }

    public function delete(User $user, CompanyDocument $document): bool
    {
        return $user->can('companies.manage')
            || $document->company->users()
                ->whereKey($user->getKey())
                ->wherePivotIn('role', [CompanyUserRole::Owner->value])
                ->exists();
    }
}
