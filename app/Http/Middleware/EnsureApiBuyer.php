<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API counterpart to EnsureBuyerAccount.
 *
 * Same definition of "buyer" — a signed-in User who is neither platform staff
 * nor a member of a supplier company — but it answers with a JSON 403 instead
 * of redirecting to a panel a native client cannot render.
 *
 * The buyer commerce endpoints are already scoped by `rfqs.user_id`, so this is
 * defence in depth rather than the primary boundary: it stops a supplier's or
 * an admin's token from being used against a surface that was never designed
 * for their population.
 */
class EnsureApiBuyer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if ($user->hasAnyRole(EnsureBuyerAccount::STAFF_ROLES) || $user->companies()->exists()) {
            abort(403, 'This endpoint is for buyer accounts.');
        }

        return $next($request);
    }
}
