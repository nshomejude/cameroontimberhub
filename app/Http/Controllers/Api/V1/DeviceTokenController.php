<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/api/v1/devices` — Expo push-token registration for the mobile app.
 *
 * A token belongs to a physical install, not an account: re-registering the
 * same token (even under a different user, e.g. after logout/login as
 * someone else on the same device) simply re-points the row via
 * `updateOrCreate` keyed on the token itself.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expo_push_token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:android,ios'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $token = DeviceToken::updateOrCreate(
            ['expo_push_token' => $data['expo_push_token']],
            [
                'user_id' => $request->user()->getKey(),
                'platform' => $data['platform'],
                'device_name' => $data['device_name'] ?? null,
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['message' => 'Device registered.', 'data' => ['id' => $token->getKey()]], 201);
    }

    /**
     * Delete the CALLER's own token row matching this token string. A token
     * that does not exist, or belongs to someone else, 404s indistinguishably
     * — the caller cannot use this to probe whether a given token string is
     * registered to another account.
     */
    public function destroy(Request $request, string $token): JsonResponse
    {
        $deleted = DeviceToken::where('user_id', $request->user()->getKey())
            ->where('expo_push_token', $token)
            ->delete();

        if ($deleted === 0) {
            return response()->json(['message' => 'Device token not found.'], 404);
        }

        return response()->json(['message' => 'Device token removed.']);
    }
}
