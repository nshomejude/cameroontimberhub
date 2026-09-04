<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorStepUp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blueprint §39 step-up re-auth. Applied only to routes/flows explicitly
 * designated as high-risk (currently: none registered as web routes — the
 * one high-risk flow in this batch, verification-revocation approval, is a
 * Filament Livewire table action rather than a routed request, so it enforces
 * the same TwoFactorStepUp window directly; see
 * App\Filament\Resources\VerificationRevocationRequests\Tables\VerificationRevocationRequestsTable).
 * This middleware exists for the next high-risk *route* (e.g. a real-money
 * payment action) so it can simply add `requires.recent.2fa` without
 * duplicating the check.
 *
 * A user who has never confirmed 2FA at all is sent to the enrolment screen,
 * not the challenge screen (there is nothing to re-verify with).
 */
class RequiresRecentTwoFactor
{
    public function __construct(private readonly TwoFactorStepUp $stepUp) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.show')
                ->with('status', 'Set up two-factor authentication to continue.');
        }

        if (! $this->stepUp->isRecentlyVerified($request)) {
            return redirect()->route('two-factor.challenge.show', ['redirect_to' => $request->fullUrl()]);
        }

        return $next($request);
    }
}
