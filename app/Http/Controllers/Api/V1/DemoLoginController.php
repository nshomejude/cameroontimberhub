<?php

namespace App\Http\Controllers\Api\V1;

use App\Features\DemoLoginsEnabled;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

/**
 * Token-auth mirror of `App\Http\Controllers\Auth\DemoLoginController` (the
 * web one-click demo logins), for the mobile app.
 *
 * Same security model as the web controller — read its docblock for the
 * full rationale. The short version: the request supplies exactly one
 * value, `{persona}`, which must be a literal key of `config('demo.personas')`;
 * nothing from the request is ever used to build the account lookup, so
 * there is no input that resolves to an arbitrary account. Both routes sit
 * behind the same `App\Features\DemoLoginsEnabled` Pennant flag
 * (`DEMO_LOGINS_ENABLED`, default false) and a dedicated `demo-login`
 * rate limiter — see routes/api.php.
 *
 * Token issuance goes through `AuthController::issueTokenFor()`, the exact
 * code `AuthController::login()` uses — there is no second way to mint a
 * Sanctum token for the v1 API.
 */
class DemoLoginController extends Controller
{
    /**
     * `GET /api/v1/auth/demo-personas` — public. Never errors: when the
     * feature is disabled the list is simply empty (200), matching the web
     * behaviour of hiding the buttons rather than 404ing the listing.
     */
    public function personas(): JsonResponse
    {
        if (! Feature::active(DemoLoginsEnabled::class)) {
            return response()->json(['data' => []]);
        }

        // Only list a persona whose account has actually been seeded on this
        // environment. `DEMO_LOGINS_ENABLED` can be true (e.g. staging) before
        // `DemoLoginSeeder` has run, or a new persona can be added to the
        // config before its data is seeded — either way, a button that 404s
        // on tap is worse than one that never appears.
        $emails = collect((array) config('demo.personas', []))
            ->pluck('email')
            ->filter()
            ->values();

        $existingEmails = User::query()
            ->whereIn('email', $emails)
            ->pluck('email')
            ->flip();

        $personas = collect((array) config('demo.personas', []))
            ->filter(fn (array $persona) => isset($persona['email']) && $existingEmails->has($persona['email']))
            ->map(fn (array $persona, string $key) => [
                'key' => $key,
                'label' => $persona['label'] ?? $persona['name'] ?? $key,
                'description' => $persona['description'] ?? '',
                'icon' => $persona['icon'] ?? null,
            ])
            ->values();

        return response()->json(['data' => $personas]);
    }

    /**
     * `POST /api/v1/auth/demo-login/{persona}` — public (the point of the
     * feature), but 404s for any key that is not a literal entry in
     * `demo.personas` and 403s outright when the feature flag is off, same
     * as the web route's `demo.logins.enabled` middleware.
     */
    public function login(Request $request, string $persona): JsonResponse
    {
        abort_unless(Feature::active(DemoLoginsEnabled::class), 403, 'Demo logins are disabled.');

        /** @var array<string, array{email: string}> $personas */
        $personas = (array) config('demo.personas', []);

        abort_unless(array_key_exists($persona, $personas), 404);

        $user = User::query()->where('email', $personas[$persona]['email'])->first();

        abort_if($user === null, 404, "The demo {$persona} account has not been set up yet.");

        return response()->json([
            'data' => [
                'token' => AuthController::issueTokenFor($user, $request),
                'user' => new UserResource($user),
            ],
        ]);
    }
}
