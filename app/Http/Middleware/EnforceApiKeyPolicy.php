<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Api\ApiException;
use App\Models\ApiKeyMeta;
use App\Support\Agent\AgentPrincipal;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global API-key policy for every `api` group request carrying a bearer token.
 *
 *  1. Revocation: `api_key_metas.revoked_at` was recorded but never enforced.
 *     A revoked key now gets a 401 `token_revoked` envelope on every route,
 *     before any route-level `auth:sanctum` runs.
 *  2. Agent confinement: an agent key (`api_key_metas.kind = agent`, held by
 *     an `agent` service-account user) is
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
    /** Request attribute: ['token_id' => int, 'meta' => ?ApiKeyMeta]. */
    public const META_ATTRIBUTE = 'api_key_policy.meta';

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if ($plain === null || $plain === '') {
            return $next($request);
        }

        // Memoised on the request (App\Models\PersonalAccessToken), so the
        // auth:sanctum guard that runs next reuses this lookup.
        $token = Sanctum::$personalAccessTokenModel::findToken($plain);

        if ($token === null) {
            return $next($request);
        }

        $meta = ApiKeyMeta::query()
            ->where('personal_access_token_id', $token->getKey())
            ->first(['id', 'revoked_at', 'kind', 'company_id', 'rate_limit_tier']);

        // Shared with the `api-key` rate limiter (AppServiceProvider) so it
        // does not re-query the same row.
        $request->attributes->set(self::META_ATTRIBUTE, ['token_id' => $token->getKey(), 'meta' => $meta]);

        if ($meta?->revoked_at !== null) {
            throw new ApiException(401, 'token_revoked', 'This API key has been revoked.');
        }

        // Agent keys are recognised by their meta kind (IssueAgentApiKey is the
        // only issuer; agent users have unusable passwords, so cannot mint
        // login tokens). This avoids loading the tokenable + its roles on
        // every bearer request.
        if ($meta?->kind === AgentPrincipal::KEY_KIND && ! $request->is('api/v1/agent', 'api/v1/agent/*')) {
            throw new ApiException(403, 'agent_scope_violation', 'Agent keys may only call /api/v1/agent endpoints.');
        }

        return $next($request);
    }
}
