<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Api\ApiException;
use App\Models\ApiKeyMeta;
use App\Support\Agent\AgentPrincipal;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for `/api/v1/agent/*` (alias `agent.token`, usage
 * `agent.token:agent:ingest`). Runs after `auth:sanctum` and requires:
 *   - a real personal access token (not a session/transient token),
 *   - not expired, and its ApiKeyMeta not revoked,
 *   - an ApiKeyMeta of kind `agent`,
 *   - the token's user holds the `agent` role,
 *   - every ability passed as a middleware parameter.
 * A normal company/buyer token therefore never reaches agent endpoints.
 */
class EnsureAgentToken
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new ApiException(401, 'unauthenticated', 'An agent API token is required.');
        }

        if ($token->expires_at !== null && $token->expires_at->isPast()) {
            throw new ApiException(401, 'token_expired', 'This API key has expired.');
        }

        $meta = ApiKeyMeta::query()->where('personal_access_token_id', $token->getKey())->first();

        if ($meta?->revoked_at !== null) {
            throw new ApiException(401, 'token_revoked', 'This API key has been revoked.');
        }

        if ($meta === null || $meta->kind !== AgentPrincipal::KEY_KIND || ! $user->hasRole(AgentPrincipal::ROLE)) {
            throw new ApiException(403, 'agent_token_required', 'This endpoint requires an agent API key.');
        }

        foreach ($abilities as $ability) {
            if (! $token->can($ability)) {
                throw new ApiException(403, 'missing_ability', "This API key lacks the `{$ability}` ability.");
            }
        }

        return $next($request);
    }
}
