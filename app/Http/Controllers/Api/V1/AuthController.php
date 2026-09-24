<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\RegisterAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
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
