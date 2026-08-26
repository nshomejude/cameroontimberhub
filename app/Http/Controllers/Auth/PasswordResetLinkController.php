<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:180'],
        ]);

        // Fire and forget: the broker's return status is deliberately ignored so
        // the response is identical whether or not the address has an account.
        Password::sendResetLink(['email' => $data['email']]);

        return back()->with('status', __('If that email address matches an account, we have sent a password reset link.'));
    }
}
