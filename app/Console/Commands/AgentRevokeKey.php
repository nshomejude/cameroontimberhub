<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ApiKeys\RevokeApiKey;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `agent:revoke-key {tokenId}` — revoke an API key by its Sanctum token id
 * (works for agent and company keys alike). Effective immediately: the next
 * request with that token gets a `token_revoked` 401.
 */
class AgentRevokeKey extends Command
{
    protected $signature = 'agent:revoke-key {tokenId : personal_access_tokens.id}';

    protected $description = 'Revoke an API key (agent or company) by token id.';

    public function handle(RevokeApiKey $revoke): int
    {
        $token = PersonalAccessToken::query()->find((int) $this->argument('tokenId'));

        if ($token === null) {
            $this->error('No token with that id.');

            return self::FAILURE;
        }

        $revoke->execute($token);

        $this->info("Token {$token->getKey()} revoked.");

        return self::SUCCESS;
    }
}
