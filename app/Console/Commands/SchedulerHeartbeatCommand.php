<?php

namespace App\Console\Commands;

use App\Support\Ops\OpsProbes;
use Illuminate\Console\Command;

/**
 * Scheduled every minute (routes/console.php). Writes a timestamp to the
 * cache so /up/health and `launch:check` can tell whether cron is actually
 * driving `schedule:run` — the outbox relay, digests and renewals all stop
 * silently otherwise.
 */
class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'ops:scheduler-heartbeat';

    protected $description = 'Record a scheduler heartbeat (proves cron is running schedule:run)';

    public function handle(): int
    {
        OpsProbes::recordSchedulerHeartbeat();

        return self::SUCCESS;
    }
}
