<?php

namespace App\Services;

use Illuminate\Http\Request;

/**
 * Blueprint §39 step-up re-auth: tracks, in the session, when a user last
 * confirmed a live TOTP (or recovery) code, and answers whether that is
 * "recent enough" to authorise a high-risk action without re-prompting.
 *
 * Shared by App\Http\Middleware\RequiresRecentTwoFactor (for route-based
 * flows) and by the Filament revocation-approval table action directly
 * (Livewire actions never pass through route middleware), so both enforce
 * the exact same window from one place.
 */
class TwoFactorStepUp
{
    private const SESSION_KEY = 'two_factor_verified_at';

    /** Minutes a confirmed TOTP code counts as "recent" for step-up checks. */
    private const RECENT_MINUTES = 15;

    public function markVerified(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->timestamp);
    }

    public function isRecentlyVerified(Request $request): bool
    {
        $timestamp = $request->session()->get(self::SESSION_KEY);

        if (! $timestamp) {
            return false;
        }

        return now()->timestamp - (int) $timestamp <= self::RECENT_MINUTES * 60;
    }
}
