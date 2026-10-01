<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Http\Requests\Api\V1\UpdateMeRequest;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Models\NotificationPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * `/account/settings` — the web counterpart of `PATCH /api/v1/auth/me`,
 * `POST /api/v1/auth/password` and `/api/v1/notifications/preferences`.
 *
 * Validation is borrowed from the API FormRequests' own rule sets (plus
 * `name` required, since the web form always posts it), so the two surfaces
 * can never drift. A password change revokes every Sanctum token and every
 * other stored web session of the user, mirroring the API's "sign every
 * other device out" behaviour; the current session is regenerated.
 */
class AccountSettingsController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('public.account.settings', [
            'user' => $user,
            'locales' => SetLocale::SUPPORTED,
            'preferences' => NotificationPreference::forUser($user),
            'channelKeys' => NotificationPreference::CHANNEL_KEYS,
            'typeKeys' => NotificationPreference::TYPE_KEYS,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $rules = (new UpdateMeRequest)->rules();
        $rules['name'] = ['required', 'string', 'max:255'];

        $data = $request->validateWithBag('profile', $rules);

        $user = $request->user();
        $user->fill(array_intersect_key($data, array_flip(['name', 'phone', 'locale'])));
        $user->save();

        if (filled($user->locale)) {
            $request->session()->put('locale', $user->locale);
        }

        return redirect()->route('account.settings')->with('status', __('messages.account_center.profile_saved'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('password', (new UpdatePasswordRequest)->rules());

        $user = $request->user();

        if (! Hash::check($data['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The provided password does not match your current password.'),
            ])->errorBag('password');
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Sign every other device out: all API tokens, and every other stored
        // web session (database driver only — other drivers keep no per-user
        // index to revoke from).
        $user->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $user->getKey())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        $request->session()->regenerate();

        return redirect()->route('account.settings')->with('status', __('messages.account_center.password_saved'));
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $request->validate([
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['boolean'],
            'types' => ['sometimes', 'array'],
            'types.*' => ['boolean'],
        ]);

        // An unchecked checkbox posts nothing, so every known key is read
        // explicitly; unknown keys are simply ignored.
        $channels = [];
        foreach (NotificationPreference::CHANNEL_KEYS as $key) {
            $channels[$key] = $request->boolean('channels.'.$key);
        }

        $types = [];
        foreach (NotificationPreference::TYPE_KEYS as $key) {
            $types[$key] = $request->boolean('types.'.$key);
        }

        $pref = NotificationPreference::forUser($request->user());
        $pref->forceFill([
            'channels' => array_merge($pref->channels ?? [], $channels),
            'types' => array_merge($pref->types ?? [], $types),
        ])->save();

        return redirect()->route('account.settings')->with('status', __('messages.account_center.preferences_saved'));
    }
}
