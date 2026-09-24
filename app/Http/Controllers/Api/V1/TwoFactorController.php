<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mobile-app counterpart of the web `Auth\TwoFactorController` (blueprint
 * §39) — same trait (`TwoFactorAuthenticatable`), same TOTP package
 * (`pragmarx/google2fa-laravel`), no parallel implementation.
 *
 * `enable`/`confirm`/`disable`/`show` require an authenticated Sanctum
 * token, exactly like the web routes require a session — they manage the
 * caller's OWN 2FA settings, before or after the login flow below cares
 * about them.
 *
 * `challenge` is the one PUBLIC endpoint here: it is the second half of a
 * login that `AuthController::login()` paused because the account has 2FA
 * confirmed. See that method's docblock for the `challenge_token` mechanism.
 */
class TwoFactorController extends Controller
{
    /** Cache key prefix for a pending login challenge. */
    private const CHALLENGE_PREFIX = 'two-factor-challenge:';

    /** Minutes a challenge token stays valid before it must be re-issued by a fresh login attempt. */
    public const CHALLENGE_TTL_MINUTES = 5;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Starts (or restarts) enrolment: generates a new unconfirmed secret and
     * returns it plus the `otpauth://` URL. No QR SVG is rendered here — the
     * web controller's `twoFactorQrCodeSvg()` renders straight into a Blade
     * view; producing the same SVG string as a JSON field is trivial (it's
     * already a plain string return), so it IS included, but a client is
     * free to ignore it and render its own QR from `otpauth_url` instead
     * (most authenticator apps' scan flows work from that URL alone).
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();
        $secret = $user->generateTwoFactorSecret();

        return response()->json([
            'data' => [
                'secret' => $secret,
                'otpauth_url' => app(\PragmaRX\Google2FA\Google2FA::class)->getQRCodeUrl(
                    config('app.name'),
                    $user->email,
                    $secret,
                ),
                'qr_svg' => $user->twoFactorQrCodeSvg($secret),
            ],
        ]);
    }

    /**
     * Confirms enrolment with a live TOTP code. Same validation the web
     * `confirm()` action performs, via the same trait methods.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (is_null($user->two_factor_secret)) {
            throw ValidationException::withMessages(['code' => 'No pending two-factor setup to confirm.']);
        }

        if (! $user->verifyTwoFactorCode($data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is invalid or has expired.']);
        }

        $codes = $user->confirmTwoFactor();

        return response()->json([
            'data' => [
                'recovery_codes' => $codes,
            ],
        ], 201);
    }

    /**
     * Disables 2FA. Accepts EITHER the current password (mirrors the web
     * flow's safety check) OR a live TOTP/recovery code — a mobile client
     * may not always want to re-prompt for the account password when the
     * user just proved possession of the authenticator a moment ago, so
     * both proofs are accepted; at least one is required.
     */
    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['nullable', 'string'],
            'code' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        $verified = false;

        if (filled($data['password'] ?? null)) {
            $verified = Hash::check($data['password'], (string) $user->password);
        }

        if (! $verified && filled($data['code'] ?? null)) {
            $verified = $user->verifyTwoFactorCode($data['code']) || $user->consumeRecoveryCode($data['code']);
        }

        if (! $verified) {
            throw ValidationException::withMessages([
                'password' => 'Provide your current password or a valid two-factor code to disable two-factor authentication.',
            ]);
        }

        $user->disableTwoFactor();

        return response()->json([
            'data' => ['enabled' => false],
        ]);
    }

    /**
     * Completes a login that `AuthController::login()` paused for 2FA.
     * Accepts either a live TOTP `code` or a `recovery_code` — exactly the
     * two proofs the web step-up challenge accepts
     * (`verifyTwoFactorCode() || consumeRecoveryCode()`).
     *
     * The challenge token is single-use and is invalidated here on EVERY
     * outcome (success or failure) so it cannot be replayed or brute-forced
     * across multiple attempts — a wrong code means the client must go back
     * through `login()` to get a fresh one, which is what makes the
     * `throttle:api-2fa-challenge` limiter on the route meaningful.
     */
    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        if (blank($data['code'] ?? null) && blank($data['recovery_code'] ?? null)) {
            throw ValidationException::withMessages(['code' => 'A code or recovery code is required.']);
        }

        $cacheKey = self::CHALLENGE_PREFIX.$data['challenge_token'];
        $userId = Cache::get($cacheKey);

        // Single-use regardless of outcome.
        Cache::forget($cacheKey);

        if ($userId === null) {
            throw ValidationException::withMessages(['challenge_token' => 'This login challenge has expired. Please log in again.']);
        }

        $user = User::find($userId);

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['challenge_token' => 'This login challenge is no longer valid.']);
        }

        $verified = filled($data['code'] ?? null) && $user->verifyTwoFactorCode($data['code'])
            || filled($data['recovery_code'] ?? null) && $user->consumeRecoveryCode($data['recovery_code']);

        if (! $verified) {
            throw ValidationException::withMessages(['code' => 'That code is invalid or has expired.']);
        }

        return response()->json([
            'data' => [
                'token' => AuthController::issueTokenFor($user, $request),
                'user' => new \App\Http\Resources\Api\V1\UserResource($user),
            ],
        ]);
    }

    /**
     * Issues a short-lived, single-use challenge token for a login that
     * requires 2FA. Chosen mechanism: a random 64-char string used as a
     * cache key mapped to the user id, TTL'd at
     * `self::CHALLENGE_TTL_MINUTES` — deliberately NOT a Sanctum token (it
     * authorises nothing beyond completing this one challenge) and NOT a
     * signed URL (there is no route/URL for a native client to sign against
     * here, just an opaque value round-tripped in a JSON body). `Cache` is
     * the simplest correct primitive for "one opaque value, mapped to one
     * fact, expires on its own, deleted after first use".
     */
    public static function issueChallengeToken(User $user): string
    {
        $token = Str::random(64);

        Cache::put(self::CHALLENGE_PREFIX.$token, $user->getKey(), now()->addMinutes(self::CHALLENGE_TTL_MINUTES));

        return $token;
    }
}
