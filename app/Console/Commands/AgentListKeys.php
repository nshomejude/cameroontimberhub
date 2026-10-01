<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKeyMeta;
use Illuminate\Console\Command;

/**
 * Listing helper kept next to create/revoke so the three CLI verbs read as one
 * surface. Prints token id, owner, abilities, expiry and revocation state.
 */
class AgentListKeys extends Command
{
    protected $signature = 'agent:list-keys';

    protected $description = 'List Agent Ingestion Gateway API keys.';

    public function handle(): int
    {
        $rows = ApiKeyMeta::query()
            ->where('kind', 'agent')
            ->with('personalAccessToken.tokenable')
            ->orderBy('id')
            ->get()
            ->map(fn (ApiKeyMeta $m) => [
                $m->personal_access_token_id,
                $m->personalAccessToken?->name,
                $m->personalAccessToken?->tokenable?->email,
                implode(',', (array) $m->personalAccessToken?->abilities),
                $m->personalAccessToken?->expires_at?->toDateString() ?? 'never',
                $m->personalAccessToken?->last_used_at?->toDateTimeString() ?? '-',
                $m->revoked_at?->toDateTimeString() ?? '-',
            ]);

        $this->table(['Token id', 'Name', 'User', 'Abilities', 'Expires', 'Last used', 'Revoked'], $rows->all());

        return self::SUCCESS;
    }
}
