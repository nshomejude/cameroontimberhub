<?php

namespace App\Http\Middleware;

use App\Features\DemoLoginsEnabled;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * The actual authorisation boundary for the demo-login route (see
 * DemoLoginController's docblock and docs/GAP_PLAN.md item 0.3): hiding the
 * login-page buttons is cosmetic, this is the gate. Applied directly to the
 * `demo.login` route so a flagged-off feature 404s before any controller
 * code — including the persona allow-list check and Auth::login() — runs.
 */
class EnsureDemoLoginsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Feature::active(DemoLoginsEnabled::class), 404);

        return $next($request);
    }
}
