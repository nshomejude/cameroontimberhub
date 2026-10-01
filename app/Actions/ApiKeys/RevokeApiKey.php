<?php

declare(strict_types=1);

namespace App\Actions\ApiKeys;

use App\Models\ApiKeyMeta;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Revoke an API key (company or agent). Stamps `api_key_metas.revoked_at` —
 * which App\Http\Middleware\EnforceApiKeyPolicy enforces on every API request
 * with a `token_revoked` 401 — and also expires the Sanctum token itself so
 * the key is dead even on a route outside that middleware. The token row is
 * kept for the audit trail and usage history.
 */
class RevokeApiKey
{
    public function execute(PersonalAccessToken $token, ?User $actor = null): ApiKeyMeta
    {
        $meta = ApiKeyMeta::query()->firstOrCreate(
            ['personal_access_token_id' => $token->getKey()],
            ['kind' => 'company', 'rate_limit_tier' => (string) config('api.rate_limit_tiers.default', 'basic')],
        );

        if ($meta->revoked_at === null) {
            $meta->forceFill(['revoked_at' => now()])->save();
        }

        $token->forceFill(['expires_at' => now()])->save();

        $logger = activity('agent-ingestion')->performedOn($meta)->event('api_key_revoked')
            ->withProperties(['token_id' => $token->getKey()]);
        if ($actor) {
            $logger->causedBy($actor);
        }
        $logger->log('API key revoked');

        return $meta;
    }
}
