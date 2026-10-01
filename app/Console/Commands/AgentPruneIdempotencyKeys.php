<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drop `agent_idempotency_keys` rows older than 7 days — the replay window
 * documented in docs/api/AGENT_INGESTION.md. Scheduled daily in routes/console.php.
 */
class AgentPruneIdempotencyKeys extends Command
{
    protected $signature = 'agent:prune-idempotency-keys {--days=7}';

    protected $description = 'Delete agent Idempotency-Key records older than N days.';

    public function handle(): int
    {
        $deleted = DB::table('agent_idempotency_keys')
            ->where('created_at', '<', now()->subDays(max(1, (int) $this->option('days'))))
            ->delete();

        $this->info("Pruned {$deleted} idempotency key(s).");

        return self::SUCCESS;
    }
}
