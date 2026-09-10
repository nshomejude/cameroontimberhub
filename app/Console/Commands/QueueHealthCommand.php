<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Production-readiness plan Task A4: a passive queue monitor, scheduled every
 * 15 minutes. It never gates anything (always exits 0) — its job is to make
 * two silent failure modes loud on the `errors` log channel:
 *
 *  1. failed_jobs is growing — a job class is dying repeatedly.
 *  2. The oldest un-reserved row in `jobs` is older than 5 minutes — the
 *     queue worker (or, via the every-10s outbox relay, the whole event
 *     backbone) has stopped draining work.
 *
 * The last-seen failed_jobs count is persisted in the cache so a warning only
 * fires on growth, not on a steady-state backlog that ops already knows about.
 */
class QueueHealthCommand extends Command
{
    protected $signature = 'ops:queue-health';

    protected $description = 'Monitor queue health (failed-job growth, pending-job / outbox-relay starvation) and alert on the errors channel';

    private const CACHE_KEY = 'ops:queue-health:last-failed-count';

    private const STARVATION_THRESHOLD_MINUTES = 5;

    public function handle(): int
    {
        $summary = [];

        $summary[] = $this->checkFailedJobs();
        $summary[] = $this->checkPendingStarvation();

        $this->info('Queue health: '.implode(' | ', $summary));

        // Always 0 — this is a monitor, not a gate.
        return self::SUCCESS;
    }

    private function checkFailedJobs(): string
    {
        $current = DB::table('failed_jobs')->count();
        $lastSeen = (int) Cache::get(self::CACHE_KEY, 0);

        if ($current > 0 && $current > $lastSeen) {
            $latest = DB::table('failed_jobs')->orderByDesc('failed_at')->first();
            [$jobClass, $exception] = $this->describeFailure($latest);

            Log::channel('errors')->warning('ops:queue-health — failed_jobs count grew since the last check.', [
                'command' => self::class,
                'failed_jobs_total' => $current,
                'previous_total' => $lastSeen,
                'new_failures' => $current - $lastSeen,
                'latest_job' => $jobClass,
                'latest_exception' => $exception,
            ]);
        }

        Cache::put(self::CACHE_KEY, $current, now()->addDays(7));

        return "failed_jobs={$current} (prev {$lastSeen})";
    }

    private function checkPendingStarvation(): string
    {
        $oldest = DB::table('jobs')
            ->whereNull('reserved_at')
            ->orderBy('available_at')
            ->first();

        if ($oldest === null) {
            return 'oldest_pending=none';
        }

        $availableAt = Carbon::createFromTimestamp($oldest->available_at ?? $oldest->created_at);
        $ageMinutes = (int) $availableAt->diffInMinutes(now());

        if ($ageMinutes >= self::STARVATION_THRESHOLD_MINUTES) {
            Log::channel('errors')->warning('ops:queue-health — oldest un-reserved job exceeds the starvation threshold; the queue worker or outbox relay may be down.', [
                'command' => self::class,
                'oldest_job_id' => $oldest->id,
                'queue' => $oldest->queue,
                'age_minutes' => $ageMinutes,
                'threshold_minutes' => self::STARVATION_THRESHOLD_MINUTES,
                'available_at' => $availableAt->toIso8601String(),
            ]);
        }

        return "oldest_pending={$ageMinutes}m";
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function describeFailure(?object $row): array
    {
        if ($row === null) {
            return [null, null];
        }

        $jobClass = null;
        $payload = json_decode((string) ($row->payload ?? ''), true);

        if (is_array($payload)) {
            $jobClass = $payload['displayName']
                ?? ($payload['data']['commandName'] ?? null);
        }

        $exception = is_string($row->exception ?? null)
            ? (strtok($row->exception, "\n") ?: $row->exception)
            : null;

        return [$jobClass, $exception];
    }
}
