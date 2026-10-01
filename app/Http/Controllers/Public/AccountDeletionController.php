<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Account\DeleteAccount;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Web account deletion: a confirmation page (`account.delete`) that any
 * settings page can link to, and the POST that performs it through the same
 * DeleteAccount action as `DELETE /api/v1/auth/me`. Open to every signed-in
 * non-staff user (buyers AND company members), so it is not in the `buyer`
 * route group.
 */
class AccountDeletionController extends Controller
{
    public function show(Request $request): View
    {
        return view('public.account.delete', ['user' => $request->user()]);
    }

    public function destroy(Request $request, DeleteAccount $deleteAccount): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
        ]);

        $user = $request->user();

        if (! Hash::check((string) $request->input('password'), (string) $user->password)) {
            throw ValidationException::withMessages(['password' => __('The provided password does not match your current password.')]);
        }

        try {
            $deleteAccount($user);
        } catch (ApiException $e) {
            return back()->withErrors(['account' => $e->getMessage()]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', __('Your account has been deleted.'));
    }
}
