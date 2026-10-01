<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ApiKeys\IssueAgentApiKey;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `agent:create-key {name}` — mint a machine-principal API key for the Agent
 * Ingestion Gateway (see App\Actions\ApiKeys\IssueAgentApiKey and
 * docs/api/AGENT_INGESTION.md). The plain-text token is printed ONCE.
 */
class AgentCreateKey extends Command
{
    protected $signature = 'agent:create-key {name : Agent name, e.g. "Hermes"}
        {--abilities=agent:ingest,agent:read : Comma-separated abilities}
        {--expires-days=365 : Days until the token expires (0 = never)}';

    protected $description = 'Create an Agent Ingestion Gateway API key on a dedicated service-account user.';

    public function handle(IssueAgentApiKey $issue): int
    {
        $abilities = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('abilities')))));
        $days = (int) $this->option('expires-days');

        try {
            $result = $issue->execute((string) $this->argument('name'), $abilities, $days > 0 ? $days : null);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Agent key created for '.$result['user']->email.' (token id '.$result['meta']->personal_access_token_id.').');
        $this->warn('Store this token now — it will not be shown again:');
        $this->line($result['plain_text_token']);

        return self::SUCCESS;
    }
}
