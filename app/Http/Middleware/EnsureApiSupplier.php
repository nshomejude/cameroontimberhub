<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for supplier-only API routes.
 *
 * A "supplier" here is any signed-in User who belongs to at least one
 * company (`company_user`) — the same test `UserResource::resolveRole()`
 * uses for the `supplier` role. Staff are not excluded here the way
 * `EnsureApiBuyer` excludes them from the buyer population, because no
 * supplier-only route exists yet that a staff member with a company
 * membership would need blocking from; add that exclusion if/when one
 * does.
 */
class EnsureApiSupplier
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if (! $user->companies()->exists()) {
            abort(403, 'This endpoint is for supplier accounts.');
        }

        return $next($request);
    }
}
