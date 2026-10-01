<?php

namespace App\Http\Controllers;

use App\Support\Ops\OpsProbes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Liveness + readiness probe. Public and unauthenticated by design — it is a
 * load-balancer / uptime-monitor target, exposes no data, and must answer
 * even when the app is otherwise refusing traffic.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->safe(fn () => DB::connection()->getPdo() !== null),
            'cache' => $this->safe(function (): bool {
                // Round-trip a unique token. Loose match on purpose: some
                // cache drivers (redis via predis) return scalars as strings,
                // so a strict === would report a healthy cache as down.
                $token = Str::random(16);
                Cache::put('health:ping', $token, 5);

                return (string) Cache::get('health:ping') === $token;
            }),
            // Backlog guard on the CONFIGURED queue connection (database,
            // redis, ...), not a hard-coded `jobs` table count.
            'queue' => $this->safe(fn () => OpsProbes::queueSize() < 10_000),
            // Cron → schedule:run alive? A stale heartbeat always fails; a
            // never-recorded one fails only in production (fresh local/test
            // environments have no cron).
            'scheduler' => $this->safe(fn () => OpsProbes::schedulerIsFresh()
                || (OpsProbes::lastSchedulerHeartbeat() === null && ! app()->isProduction())),
        ];

        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ], $ok ? 200 : 503);
    }

    private function safe(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (\Throwable) {
            return false;
        }
    }
}
