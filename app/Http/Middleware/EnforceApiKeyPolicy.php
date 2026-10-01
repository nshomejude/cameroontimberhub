<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Api\ApiException;
use App\Models\ApiKeyMeta;
use App\Models\User;
use App\Support\Agent\AgentPrincipal;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global API-key policy for every `api` group request carrying a bearer token.
 *
 *  1. Revocation: `api_key_metas.revoked_at` was recorded but never enforced.
 *     A revoked key now gets a 401 `token_revoked` envelope on every route,
 *     before any route-level `auth:sanctum` runs.
 *  2. Agent confinement: a token held by an `agent` service-account user is
 *     only valid on `/api/v1/agent/*`. Anywhere else (buyer, supplier, chat,
 *     notifications, ...) it gets a 403 `agent_scope_violation`, so a machine
 *     principal can never act as a buyer or reach the generic authenticated
 *     surface even though it has no company.
 *
 * Unknown/invalid tokens are left alone — `auth:sanctum` reports those as
 * the usual 401 `unauthenticated`.
 */
class EnforceApiKeyPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if ($plain === null || $plain === '') {
            return $next($request);
        }

        $token = PersonalAccessToken::findToken($plain);

        if ($token === null) {
            return $next($request);
        }

        $meta = ApiKeyMeta::query()->where('personal_access_token_id', $token->getKey())->first(['id', 'revoked_at', 'kind']);

        if ($meta?->revoked_at !== null) {
            throw new ApiException(401, 'token_revoked', 'This API key has been revoked.');
        }

        $tokenable = $token->tokenable;

        if ($tokenable instanceof User && $tokenable->hasRole(AgentPrincipal::ROLE) && ! $request->is('api/v1/agent', 'api/v1/agent/*')) {
            throw new ApiException(403, 'agent_scope_violation', 'Agent keys may only call /api/v1/agent endpoints.');
        }

        return $next($request);
    }
}
