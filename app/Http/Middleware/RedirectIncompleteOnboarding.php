<?php

namespace App\Http\Middleware;

use App\Filament\Exporter\Pages\OnboardingChecklist;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied to the exporter Filament panel. Steers a company-owning user to the
 * onboarding checklist page on their first panel visit of the session while
 * their company's checklist is incomplete, so a freshly-registered company
 * "lands" there without a Filament homeUrl hack. Once the checklist is
 * complete (or the user has already been redirected once this session) they
 * navigate freely — this is a one-time nag, not a permanent gate, so a user
 * who deliberately clicks away from the checklist is never trapped on it.
 */
class RedirectIncompleteOnboarding
{
    private const SESSION_KEY = 'exporter_onboarding_redirect_shown';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->companies()->exists()) {
            return $next($request);
        }

        if ($request->routeIs('filament.exporter.pages.onboarding-checklist')) {
            return $next($request);
        }

        // Only nag on the panel's actual landing page (the Dashboard). Any
        // other panel route -- a resource list, a Livewire form post, an API
        // style GET used by tests/automation -- should behave normally; a
        // blanket "first request of the session" gate here would silently
        // redirect legitimate direct visits to unrelated exporter pages.
        if (! $request->routeIs('filament.exporter.pages.dashboard')) {
            return $next($request);
        }

        if ($request->session()->has(self::SESSION_KEY)) {
            return $next($request);
        }

        $checklist = new OnboardingChecklist();

        if ($checklist->getCompletedCount() < $checklist->getTotalCount()) {
            $request->session()->put(self::SESSION_KEY, true);

            return redirect()->to(OnboardingChecklist::getUrl());
        }

        return $next($request);
    }
}
