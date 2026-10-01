<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterAccount;
use App\Http\Controllers\Auth\Concerns\ProvidesAuthPageStats;
use App\Http\Controllers\Auth\Concerns\RedirectsAfterAuth;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorStepUp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    use ProvidesAuthPageStats, RedirectsAfterAuth;

    /** Session keys holding a password-verified login awaiting its 2FA code. */
    public const PENDING_ID = 'login.two_factor.id';

    public const PENDING_REMEMBER = 'login.two_factor.remember';

    public const PENDING_AT = 'login.two_factor.at';

    /** Minutes a pending two-factor login stays valid. */
    private const PENDING_TTL_MINUTES = 5;

    public function create(): View
    {
        return view('auth.login', ['stats' => $this->authPageStats()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => RegisterAccount::normaliseEmail($request->input('email'))]);

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:180'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        // Validate the credentials WITHOUT logging in: an account with
        // confirmed 2FA must pass the code challenge first (blueprint §39).
        if (! Auth::validate(['email' => $data['email'], 'password' => $data['password']])) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        /** @var User $user */
        $user = Auth::getProvider()->retrieveByCredentials(['email' => $data['email']]);

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put([
                self::PENDING_ID => $user->getKey(),
                self::PENDING_REMEMBER => $request->boolean('remember'),
                self::PENDING_AT => now()->getTimestamp(),
            ]);

            return redirect()->route('login.two-factor');
        }

        Auth::login($user, $request->boolean('remember'));

        return $this->completeLogin($request, $user);
    }

    /** Second step of a web login for an account with confirmed 2FA. */
    public function showTwoFactor(Request $request): View|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return redirect()->route('login');
        }

        return view('auth.two-factor.challenge', [
            'redirectTo' => null,
            'action' => route('login.two-factor.store'),
            'heading' => __('Two-factor authentication'),
            'intro' => __('Enter the code from your authenticator app (or a recovery code) to finish signing in.'),
        ]);
    }

    public function storeTwoFactor(Request $request, TwoFactorStepUp $stepUp): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Your sign-in expired. Please log in again.')]);
        }

        $code = trim($data['code']);

        if (! $user->verifyTwoFactorCode($code) && ! $user->consumeRecoveryCode($code)) {
            throw ValidationException::withMessages(['code' => __('That code is invalid or has expired.')]);
        }

        $remember = (bool) $request->session()->get(self::PENDING_REMEMBER, false);
        $request->session()->forget([self::PENDING_ID, self::PENDING_REMEMBER, self::PENDING_AT]);

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $stepUp->markVerified($request);

        return redirect()->intended($this->redirectPathFor($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function completeLogin(Request $request, User $user): RedirectResponse
    {
        $request->session()->regenerate();

        // Blueprint §39: 2FA is opt-in for company/buyer users but required
        // for admin-panel staff. The /admin panel itself enforces this
        // (EnsureStaffTwoFactor); sending them straight to setup here just
        // saves a redirect hop.
        if (config('auth.require_staff_2fa', true) && $user->isStaff() && ! $user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.show')
                ->with('status', 'Two-factor authentication is required for admin accounts. Please set it up now.');
        }

        return redirect()->intended($this->redirectPathFor($user));
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING_ID);
        $at = (int) $request->session()->get(self::PENDING_AT, 0);

        if ($id === null || now()->getTimestamp() - $at > self::PENDING_TTL_MINUTES * 60) {
            return null;
        }

        $user = User::find($id);

        return $user !== null && $user->hasTwoFactorEnabled() ? $user : null;
    }
}
