<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blueprint §39: two-factor authentication is mandatory for platform staff.
 * Registered as an authMiddleware on the /admin Filament panel, so a staff
 * user (any role in User::STAFF_ROLES) without a CONFIRMED 2FA secret is sent
 * to the enrolment screen instead of reaching the panel.
 *
 * Toggle: config('auth.require_staff_2fa') / STAFF_REQUIRE_2FA (default on).
 */
class EnsureStaffTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('auth.require_staff_2fa', true)
            && $user instanceof User
            && $user->isStaff()
            && ! $user->hasTwoFactorEnabled()) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('two-factor.show')
                ->with('status', __('Two-factor authentication is required for staff accounts. Please set it up now.'));
        }

        return $next($request);
    }
}
