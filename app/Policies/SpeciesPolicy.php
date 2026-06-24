<?php

namespace App\Policies;

use App\Models\Species;
use App\Models\User;

class SpeciesPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('species.manage') || $user->can('companies.view');
    }

    public function view(User $user, Species $species): bool
    {
        return $user->can('species.manage') || $user->can('companies.view');
    }

    public function create(User $user): bool
    {
        return $user->can('species.manage');
    }

    public function update(User $user, Species $species): bool
    {
        return $user->can('species.manage');
    }

    public function delete(User $user, Species $species): bool
    {
        return $user->hasAnyRole(['super_admin', 'content_manager']);
    }
}
