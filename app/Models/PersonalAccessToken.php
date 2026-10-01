<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token model with a per-request lookup memo.
 *
 * App\Http\Middleware\EnforceApiKeyPolicy resolves the bearer token before
 * `auth:sanctum` runs; without this the guard would look the same token up
 * again. The memo lives on the current request's attributes, so it never
 * outlives one request (safe under queue workers / Octane).
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** Request attribute holding [sha256(plain) => ?token]. */
    public const REQUEST_MEMO = 'sanctum.resolved_tokens';

    protected $table = 'personal_access_tokens';

    public static function findToken($token)
    {
        $request = app()->bound('request') ? app('request') : null;

        if ($request === null || ! is_string($token)) {
            return parent::findToken($token);
        }

        $key = hash('sha256', $token);
        $memo = $request->attributes->get(self::REQUEST_MEMO, []);

        if (! array_key_exists($key, $memo)) {
            $memo[$key] = parent::findToken($token);
            $request->attributes->set(self::REQUEST_MEMO, $memo);
        }

        return $memo[$key];
    }
}
