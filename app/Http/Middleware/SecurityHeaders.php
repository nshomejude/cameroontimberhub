<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline HTTP security headers (production-readiness plan, Batch A / Task A3).
 *
 * The reverse proxy (nginx) already emits `X-Frame-Options`, `X-Content-Type-Options`,
 * `X-XSS-Protection`, `X-Permitted-Cross-Domain-Policies` and `Referrer-Policy` on the
 * live host. This middleware:
 *
 *   1. Re-asserts the framing / sniffing / referrer set in-app as defence in depth,
 *      so a misconfigured or bypassed proxy (or `php artisan serve`, or a future host)
 *      still ships them. Duplicate headers from nginx + app are harmless — nginx wins
 *      at the edge, and both values agree.
 *   2. Adds `Strict-Transport-Security` — but ONLY on HTTPS requests, never on local
 *      plain-HTTP dev, so a developer machine can't accidentally pin itself to HTTPS.
 *   3. Adds `Content-Security-Policy-Report-Only` — report-only on purpose. We collect
 *      violation reports at `/csp-report` for a week before switching to an enforcing
 *      policy in a follow-up. The allow-list is deliberately loose (inline styles +
 *      scripts) because Filament, Livewire, Alpine and the Vite runtime all need it
 *      today. Fonts are self-hosted (bundled through Vite) so no external font host is
 *      listed; there are currently no third-party script/style CDNs in use.
 *   4. Adds `Permissions-Policy` — geolocation is allowed for `self` because the PWA
 *      field-capture pages (checkpoint / inspector capture) read device location;
 *      camera / microphone / payment are denied outright.
 *
 * Appended to the `web` group only. The JSON API sets its own envelope and has no
 * browser surface to protect.
 */
class SecurityHeaders
{
    /**
     * Content-Security-Policy, sent Report-Only. Single source of truth so the
     * test and any future enforcing switch read the same string.
     */
    public const CSP = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."font-src 'self' data:; "
        ."img-src 'self' data: https:; "
        ."connect-src 'self'; "
        ."frame-ancestors 'self'; "
        ."base-uri 'self'; "
        ."form-action 'self'; "
        .'report-uri /csp-report';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Defence-in-depth re-assert of the edge-proxy set. setDefault-style:
        // only write if absent so we never fight a value the proxy already chose.
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Permitted-Cross-Domain-Policies' => 'master-only',
            'Referrer-Policy' => 'same-origin',
        ];

        foreach ($defaults as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        // HSTS: HTTPS only. Two years, include subdomains. No `preload` yet —
        // that is a one-way commitment and belongs in a deliberate follow-up.
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        // Report-only CSP — collect violations, do not block.
        $response->headers->set('Content-Security-Policy-Report-Only', self::CSP);

        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(self), camera=(), microphone=(), payment=()',
        );

        return $response;
    }
}
