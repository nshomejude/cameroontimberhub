<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the buyer account area (/account).
 *
 * A "buyer" here is a signed-in User who is neither platform staff nor a member
 * of a supplier company. The two other populations already have a home of their
 * own, and the buyer dashboard would be structurally empty for them (it reads
 * `rfqs.user_id` / `orders.user_id`), so they are sent there rather than shown a
 * blank page:
 *
 *   - platform staff        -> /admin   (Filament admin panel)
 *   - company members       -> /dashboard (Filament exporter panel)
 *
 * Guests are handled upstream by the `auth` middleware, which preserves the
 * intended URL, so this class never has to think about them.
 *
 * The rule is deliberately identical to RedirectsAfterAuth so there is exactly
 * one definition of "where does this user belong" in the codebase.
 */
class EnsureBuyerAccount
{
    /** Platform staff roles that gate the /admin Filament panel. */
    public const STAFF_ROLES = ['super_admin', 'admin', 'verification_officer', 'content_manager'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if ($user->hasAnyRole(self::STAFF_ROLES)) {
            return redirect()->to('/admin');
        }

        // A company-owning user who ALSO holds the `buyer` account role
        // (brief §3.1's "a sawmill both supplies and buys processing") is
        // allowed through to buyer-only routes rather than always being
        // bounced to /dashboard purely because they own a company.
        if ($user->companies()->exists() && ! $user->hasRole('buyer')) {
            return redirect()->to('/dashboard');
        }

        return $next($request);
    }
}
