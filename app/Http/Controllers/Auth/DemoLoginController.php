<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\RedirectsAfterAuth;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * One-click demo logins for the public showcase.
 *
 * The whole feature is behind `config('demo.enabled')` (env
 * DEMO_LOGINS_ENABLED, default **false**). The flag is checked *here* as well
 * as in the Blade that draws the buttons, because hiding a control is not a
 * security boundary: with the flag off this route 404s for everyone.
 *
 * SECURITY NOTE — the `admin` persona is a genuine super_admin with FULL
 * access to the /admin panel. That exposure is deliberate and accepted by the
 * owner for the demo environment; `DEMO_LOGINS_ENABLED=false` disables it
 * completely (buttons and route alike).
 *
 * Why this cannot be turned into an auth bypass: the request supplies exactly
 * one value, `{persona}`, and it must be a literal key of the
 * `demo.personas` config array. Nothing from the request is ever used to build
 * the lookup — the email comes from config, not from the user — so there is no
 * input that resolves to an arbitrary account. The route is POST-only and
 * CSRF-protected, so no link, prefetch or crawler can authenticate anyone, and
 * it is rate-limited on top.
 */
class DemoLoginController extends Controller
{
    use RedirectsAfterAuth;

    public function __invoke(Request $request, string $persona): RedirectResponse
    {
        abort_unless(config('demo.enabled') === true, 404);

        /** @var array<string, array{email: string}> $personas */
        $personas = (array) config('demo.personas', []);

        // Allow-list. Anything that is not one of these three literal keys —
        // an email, `../admin`, an id — never reaches a query.
        abort_unless(array_key_exists($persona, $personas), 404);

        $user = User::query()->where('email', $personas[$persona]['email'])->first();

        if ($user === null) {
            return back()->withErrors([
                'email' => __('The demo :persona account has not been set up yet.', ['persona' => $persona]),
            ]);
        }

        // No password is involved at any point: the seeded accounts carry
        // strong random passwords that nothing (UI, log or flash message) ever
        // knows or prints.
        Auth::login($user);

        $request->session()->regenerate();

        // Same single source of truth as LoginController/RegisterController.
        return redirect()->to($this->redirectPathFor($user));
    }
}
