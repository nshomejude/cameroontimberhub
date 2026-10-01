<?php

namespace App\Support\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Shared, driver-agnostic probes used by /up/health, `ops:queue-health` and
 * `launch:check`. Everything goes through the *configured* queue connection
 * (QUEUE_CONNECTION — database, redis, ...) rather than assuming the DB
 * `jobs` table, and through the scheduler heartbeat that the every-minute
 * `ops:scheduler-heartbeat` task writes.
 */
final class OpsProbes
{
    public const HEARTBEAT_KEY = 'ops:scheduler:heartbeat';

    /** Heartbeat older than this means `schedule:run` (cron) has stopped. */
    public const HEARTBEAT_STALE_SECONDS = 180;

    public static function queueConnectionName(): string
    {
        return (string) config('queue.default');
    }

    public static function queueDriver(): ?string
    {
        return config('queue.connections.'.self::queueConnectionName().'.driver');
    }

    /** Total jobs waiting on the configured connection's default queue. */
    public static function queueSize(): int
    {
        return (int) Queue::connection()->size();
    }

    /**
     * Age in seconds of the oldest pending (available, un-reserved) job on the
     * configured connection's default queue, or null when none / unsupported.
     */
    public static function oldestPendingAgeSeconds(): ?int
    {
        $connection = Queue::connection();

        if (! method_exists($connection, 'creationTimeOfOldestPendingJob')) {
            return null;
        }

        $timestamp = $connection->creationTimeOfOldestPendingJob();

        if ($timestamp === null) {
            return null;
        }

        return max(0, now()->getTimestamp() - (int) $timestamp);
    }

    public static function recordSchedulerHeartbeat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->getTimestamp());
    }

    public static function lastSchedulerHeartbeat(): ?Carbon
    {
        $value = Cache::get(self::HEARTBEAT_KEY);

        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : null;
    }

    public static function schedulerIsFresh(): bool
    {
        $last = self::lastSchedulerHeartbeat();

        return $last !== null
            && $last->diffInSeconds(now(), true) <= self::HEARTBEAT_STALE_SECONDS;
    }
}
