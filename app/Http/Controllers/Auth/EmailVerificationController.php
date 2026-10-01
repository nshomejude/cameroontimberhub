<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAfterAuth;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Email verification for the hand-rolled auth stack (no Fortify/Breeze).
 *
 * Unverified users can still sign in (launch decision: no hard block). Proving
 * the address unlocks the things that must not ride on an unverified email:
 * adopting earlier guest RFQs (AdoptGuestRfqsOnEmailVerified, on the Verified
 * event fired here) and starting new conversations.
 *
 * `verify` is deliberately NOT behind `auth`: the link is signed (route
 * `signed` middleware) and bound to the address by the sha1 hash, which is
 * all Laravel's own EmailVerificationRequest checks beyond the session — and
 * it lets a link opened outside the browser session (e.g. a mobile-app
 * signup) verify the account too.
 */
class EmailVerificationController extends Controller
{
    use RedirectsAfterAuth;

    public function notice(Request $request): View|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->to($this->redirectPathFor($request->user()));
        }

        return view('auth.verify-email');
    }

    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        abort_if($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        $status = __('Thanks — your email address is verified.');

        if ($request->user()?->is($user)) {
            return redirect()->to($this->redirectPathFor($user))->with('status', $status);
        }

        return redirect()->route('login')->with('status', $status);
    }

    public function send(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', __('A new verification link has been sent to your email address.'));
    }
}
