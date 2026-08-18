<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\User;

/**
 * Single source of truth for where a freshly authenticated user lands.
 * Staff first (a user can be both staff and a company member), then company
 * membership (exporter panel), then the buyer account area.
 *
 * The order and the tests here must stay in step with EnsureBuyerAccount, which
 * applies the same rule in reverse to keep staff and company members out of the
 * buyer dashboard.
 */
trait RedirectsAfterAuth
{
    /** Platform staff roles that gate the /admin Filament panel. */
    protected function staffRoles(): array
    {
        return ['super_admin', 'admin', 'verification_officer', 'content_manager'];
    }

    protected function redirectPathFor(User $user): string
    {
        if ($user->hasAnyRole($this->staffRoles())) {
            return '/admin';
        }

        if ($user->companies()->exists()) {
            return '/dashboard';
        }

        return route('account.index');
    }
}
