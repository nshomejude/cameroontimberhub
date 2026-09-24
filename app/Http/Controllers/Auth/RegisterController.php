<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterAccount;
use App\Http\Controllers\Auth\Concerns\ProvidesAuthPageStats;
use App\Http\Controllers\Auth\Concerns\RedirectsAfterAuth;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    use ProvidesAuthPageStats, RedirectsAfterAuth;

    public function create(Request $request): View
    {
        // ?ref=CODE (the share_url from /api/v1/referrals/me) prefills the
        // optional referral code field.
        $ref = $request->query('ref');

        return view('auth.register', [
            'stats' => $this->authPageStats(),
            'referralCode' => is_string($ref) ? mb_substr(strtoupper(trim($ref)), 0, 20) : null,
        ]);
    }

    public function store(Request $request, RegisterAccount $register): RedirectResponse
    {
        $data = $request->validate(RegisterAccount::rules(), [], [
            'terms' => 'terms of service',
        ]);

        // Account creation itself lives in RegisterAccount, shared with the
        // mobile API, so there is exactly one place accounts come into being.
        $user = $register($data);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended($this->redirectPathFor($user));
    }
}
