<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Lightweight per-API-key usage metering (architecture plan §4 — "API as a
 * product": you cannot sensibly price or rate-limit something you can't
 * measure). Increments today's row in `api_key_usage_dailies` for the
 * current request's Sanctum token.
 *
 * Race-safety: uses a raw `INSERT ... ON CONFLICT (personal_access_token_id,
 * date) DO UPDATE SET request_count = request_count + 1` — a single atomic
 * statement at the database level, so concurrent requests against the same
 * key on the same day accumulate correctly instead of racing on a
 * read-modify-write (which a plain `firstOrCreate()->increment()` pair would
 * be vulnerable to under concurrency without an explicit transaction/lock).
 *
 * Never allowed to block or fail the underlying API request — wrapped in
 * try/catch and logged, exactly like this codebase's established pattern for
 * non-critical side effects (see e.g. App\Observers\CompanyDocumentObserver).
 * Requests with no authenticated Sanctum token (public catalogue routes) are
 * a no-op — there is no key to attribute usage to.
 */
class RecordApiKeyUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $token = $request->user()?->currentAccessToken();

            if ($token) {
                DB::statement(
                    <<<'SQL'
                        insert into api_key_usage_dailies (personal_access_token_id, date, request_count, created_at, updated_at)
                        values (?, ?, 1, now(), now())
                        on conflict (personal_access_token_id, date)
                        do update set request_count = api_key_usage_dailies.request_count + 1, updated_at = now()
                    SQL,
                    [$token->getKey(), now()->toDateString()]
                );
            }
        } catch (Throwable $e) {
            Log::error('RecordApiKeyUsage: failed to record API key usage', [
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }
}
