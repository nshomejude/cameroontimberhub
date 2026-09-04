<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TwoFactorStepUp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Blueprint §39: self-service TOTP enrolment/management. Sits alongside the
 * hand-rolled Auth\LoginController et al. (this app has no Fortify), under
 * `auth` middleware — every signed-in user (buyer, company member, or staff)
 * manages their own 2FA here.
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorStepUp $stepUp) {}

    /**
     * Management screen: shows current status, or a QR code for a freshly
     * generated (unconfirmed) secret.
     */
    public function show(Request $request): View
    {
        $user = $request->user();

        $secret = null;
        $qrSvg = null;

        if (! $user->hasTwoFactorEnabled() && $user->two_factor_secret) {
            $secret = $user->two_factor_secret;
            $qrSvg = $user->twoFactorQrCodeSvg($secret);
        }

        return view('auth.two-factor.show', [
            'user' => $user,
            'secret' => $secret,
            'qrSvg' => $qrSvg,
        ]);
    }

    /**
     * Start (or restart) enrolment: generates a new unconfirmed secret.
     */
    public function enable(Request $request): RedirectResponse
    {
        $user = $request->user();
        $secret = $user->generateTwoFactorSecret();
        $qrSvg = $user->twoFactorQrCodeSvg($secret);

        return redirect()->route('two-factor.show')
            ->with('two_factor_secret', $secret)
            ->with('two_factor_qr', $qrSvg);
    }

    /**
     * Confirm enrolment with a TOTP code from the authenticator app.
     */
    public function confirm(Request $request): RedirectResponse|View
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
        $this->stepUp->markVerified($request);

        return view('auth.two-factor.recovery-codes', [
            'codes' => $codes,
        ]);
    }

    /**
     * Disable 2FA. Requires the current password as a safety check.
     */
    public function disable(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $request->user()->disableTwoFactor();

        return redirect()->route('two-factor.show')->with('status', 'Two-factor authentication has been disabled.');
    }

    /**
     * Regenerate recovery codes for an already-confirmed setup.
     */
    public function regenerateRecoveryCodes(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.show');
        }

        $codes = $user->regenerateRecoveryCodes();

        return view('auth.two-factor.recovery-codes', [
            'codes' => $codes,
        ]);
    }

    /**
     * Step-up re-verification challenge: confirm a fresh TOTP code (or a
     * recovery code) to refresh the "recently verified" session timestamp
     * that App\Http\Middleware\RequiresRecentTwoFactor checks.
     */
    public function showChallenge(Request $request): View
    {
        return view('auth.two-factor.challenge', [
            'redirectTo' => $request->query('redirect_to', url()->previous()),
        ]);
    }

    public function challenge(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'redirect_to' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        $verified = $user->verifyTwoFactorCode($data['code']) || $user->consumeRecoveryCode($data['code']);

        if (! $verified) {
            throw ValidationException::withMessages(['code' => 'That code is invalid or has expired.']);
        }

        $this->stepUp->markVerified($request);

        return redirect()->to($data['redirect_to'] ?? route('two-factor.show'))
            ->with('status', 'Re-verified.');
    }
}
