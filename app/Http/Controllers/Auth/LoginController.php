<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\ProvidesAuthPageStats;
use App\Http\Controllers\Auth\Concerns\RedirectsAfterAuth;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    use ProvidesAuthPageStats, RedirectsAfterAuth;

    public function create(): View
    {
        return view('auth.login', ['stats' => $this->authPageStats()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:180'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        if (! Auth::attempt(
            ['email' => $data['email'], 'password' => $data['password']],
            $request->boolean('remember')
        )) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user();

        // Blueprint §39: 2FA is opt-in for company/buyer users but required
        // for admin-panel staff. Guarded so a role/permission hiccup never
        // locks anyone out mid-session — this only redirects the very next
        // request after a fresh login, never terminates an existing one.
        if (method_exists($user, 'hasAnyRole')
            && method_exists($user, 'hasTwoFactorEnabled')
            && $user->hasAnyRole(['super_admin', 'admin'])
            && ! $user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.show')
                ->with('status', 'Two-factor authentication is required for admin accounts. Please set it up now.');
        }

        return redirect()->intended($this->redirectPathFor($request->user()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
