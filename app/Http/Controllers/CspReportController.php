<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Collector for `Content-Security-Policy-Report-Only` violation reports
 * (production-readiness plan, Task A3).
 *
 * Browsers POST here on every blocked resource while the report-only policy is
 * live. It is unauthenticated and CSRF-exempt by necessity — a CSP report is a
 * fire-and-forget browser beacon that carries no session and no token. The body
 * may be `application/csp-report`, `application/reports+json`, or plain JSON,
 * and a hostile client can send anything at all, so this never trusts the shape
 * and never 500s: malformed input is logged as-is and still answered 204.
 *
 * Rate-limited by the `csp-report` limiter (60/min/IP) on the route.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        try {
            $payload = $request->json()->all();

            if (! is_array($payload) || $payload === []) {
                $raw = (string) $request->getContent();
                $payload = ['raw' => mb_substr($raw, 0, 4000)];
            }
        } catch (\Throwable) {
            $payload = ['raw' => mb_substr((string) $request->getContent(), 0, 4000)];
        }

        try {
            Log::channel('errors')->warning('CSP violation report', [
                'report' => $payload,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 300),
            ]);
        } catch (\Throwable) {
            // Never let logging a beacon throw.
        }

        return response()->noContent();
    }
}
