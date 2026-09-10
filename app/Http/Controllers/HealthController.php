<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
                Cache::put('health:ping', 1, 5);

                return Cache::get('health:ping') === 1;
            }),
            'queue' => $this->safe(fn () => DB::table('jobs')->count() < 10_000), // backlog guard
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
