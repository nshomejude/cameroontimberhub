<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied to the exporter Filament panel. Redirects back to login if the
 * authenticated user somehow reaches the panel without a company membership
 * (edge case: they were removed from the company after logging in).
 */
class EnsureExporterOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->companies()->exists()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('filament.exporter.auth.login')
                ->with('status', 'Your company membership could not be found. Please contact support.');
        }

        return $next($request);
    }
}
