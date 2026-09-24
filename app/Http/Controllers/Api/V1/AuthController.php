<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\RegisterAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Requests\Api\V1\UpdateMeRequest;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Token auth for the buyer app.
 *
 * Registration delegates to RegisterAccount, the same action the web form
 * calls, so hashing, the supplier/company rules and the adoption of earlier
 * account-free RFQs behave identically on both paths.
 */
class AuthController extends Controller
{
    /** Default label when the client does not name the device. */
    private const DEFAULT_DEVICE = 'mobile';

    public function register(RegisterRequest $request, RegisterAccount $register): JsonResponse
    {
        $user = $register($request->validated());

        return response()->json([
            'data' => [
                'token' => $this->issueToken($user, $request),
                'user' => new UserResource($user),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * The failure message is deliberately one generic string on the `email`
     * field for every cause — unknown address, wrong password, anything else.
     * Distinguishing them would turn this endpoint into an account-existence
     * oracle. The password check runs even when no user matched, so the
     * response time does not leak the answer either.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::whereRaw('lower(email) = ?', [strtolower(trim($data['email']))])->first();

        if ($user === null || ! Hash::check($data['password'], (string) $user->password)) {
            // Burn a hash comparison on the miss path so timing is flat.
            if ($user === null) {
                Hash::check($data['password'], '$2y$10$'.str_repeat('a', 53));
            }

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        // Blueprint §39: a user with a CONFIRMED 2FA secret must complete
        // the challenge below before a token is ever minted. This is the one
        // place that check happens — no token is issued past this point for
        // such an account without a verified TOTP/recovery code.
        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'data' => [
                    'two_factor_required' => true,
                    'challenge_token' => TwoFactorController::issueChallengeToken($user),
                ],
            ]);
        }

        return response()->json([
            'data' => [
                'token' => $this->issueToken($user, $request),
                'user' => new UserResource($user),
            ],
        ]);
    }

    /**
     * Revokes only the token that made this call — other devices stay
     * signed in. Optionally accepts `expo_push_token` in the body and, if
     * present, deletes that DEVICE token row too (scoped to the caller,
     * same as `DELETE /devices/{token}`) in this SAME request — the mobile
     * client's sign-out flow needs this atomic, because a client-side
     * "logout, then call DELETE /devices/{token}" sequence always 401s on
     * the second call once the bearer is already revoked. Sending no
     * `expo_push_token` behaves exactly as before.
     */
    public function logout(Request $request): Response
    {
        $expoPushToken = $request->string('expo_push_token')->trim()->value();

        if ($expoPushToken !== '') {
            \App\Models\DeviceToken::where('user_id', $request->user()->getKey())
                ->where('expo_push_token', $expoPushToken)
                ->delete();
        }

        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * Updates only the fields the caller actually sent — `name`, `phone`,
     * `locale` — and leaves everything else untouched. `locale` is validated
     * against `SetLocale::SUPPORTED` in UpdateMeRequest, the same list the
     * middleware already accepts, so persisting it here and reading it back
     * through that middleware later can never disagree on what is valid.
     */
    public function updateMe(UpdateMeRequest $request): UserResource
    {
        $user = $request->user();

        $user->fill($request->safe()->only(['name', 'phone', 'locale']));
        $user->save();

        return new UserResource($user->fresh());
    }

    /**
     * Changes the caller's own password. `current_password` is checked with
     * a raw `Hash::check()` against the authenticated user's own password
     * column — the same technique login() above already uses — rather than
     * the `current_password` validation rule, which validates against a
     * guard's credential provider and is awkward to point at the `sanctum`
     * guard from inside a FormRequest.
     *
     * Scope is deliberately narrow: only the password is changed. There is
     * no existing precedent in this codebase for revoking sibling tokens on
     * a password change (NewPasswordController's web reset only rotates
     * `remember_token`, which Sanctum tokens do not use), so other devices
     * are left signed in.
     */
    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        $user = $request->user();

        if (! Hash::check($request->string('current_password')->value(), (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The provided password does not match your current password.'),
            ]);
        }

        $user->forceFill([
            'password' => $request->string('password')->value(),
        ])->save();

        return response()->noContent();
    }

    /**
     * JSON counterpart of PasswordResetLinkController::store(). Calls the
     * exact same broker method and, just like the web controller, never
     * lets the broker's return status reach the client — the response is
     * identical whether or not the address has an account, preserving the
     * enumeration-safety property of the web flow.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => $request->validated('email')]);

        return response()->json([
            'data' => [
                'message' => __('If that email address matches an account, we have sent a password reset link.'),
            ],
        ]);
    }

    /**
     * JSON counterpart of NewPasswordController::store() — same broker call,
     * same closure body (forceFill password + remember_token, fire
     * PasswordReset), same "invalid or expired" message on failure, just
     * returned as JSON instead of a redirect.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => __('This password reset link is invalid or has expired.'),
            ]);
        }

        return response()->json([
            'data' => [
                'message' => __('Your password has been reset. You can now sign in.'),
            ],
        ]);
    }

    /**
     * The single place a Sanctum token is minted for the v1 API. Both
     * register()/login() above and DemoLoginController (the API mirror of
     * the web one-click demo logins) call this — there is deliberately no
     * second token-issuing code path.
     */
    public static function issueTokenFor(User $user, Request $request): string
    {
        $device = trim((string) $request->input('device_name', '')) ?: self::DEFAULT_DEVICE;

        return $user->createToken(mb_substr($device, 0, 120), ['buyer'])->plainTextToken;
    }

    private function issueToken(User $user, Request $request): string
    {
        return self::issueTokenFor($user, $request);
    }
}
