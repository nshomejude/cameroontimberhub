<?php

use App\Exceptions\Api\ErrorEnvelope;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureApiBuyer;
use App\Http\Middleware\EnsureApiSupplier;
use App\Http\Middleware\EnsureBuyerAccount;
use App\Http\Middleware\EnsureDemoLoginsEnabled;
use App\Http\Middleware\AnnounceDeprecation;
use App\Http\Middleware\EnsureExporterOnboarded;
use App\Http\Middleware\HandleSlugRedirects;
use App\Http\Middleware\RequiresRecentTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Buyer-facing JSON API (React Native client). Stateless, token-authed.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Request-correlation id (GAPS.md §6): accept-or-generate + echo.
        // Prepended so every downstream middleware, controller and the
        // exception renderer sees `request_id` on the request / in Context.
        // Safe on web — it only reads a header and sets a response header,
        // no session or CSRF interaction. (The api/v1 group adds it too, in
        // routes/api.php, so it also covers the throttle:api-key layer.)
        $middleware->web(prepend: [
            AssignRequestId::class,
        ]);
        $middleware->api(prepend: [
            AssignRequestId::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
            HandleSlugRedirects::class,
            // Baseline browser security headers (production-readiness Task A3):
            // re-asserts the nginx X-* set in-app, adds HSTS (HTTPS only),
            // Content-Security-Policy-Report-Only and Permissions-Policy.
            SecurityHeaders::class,
        ]);

        // CSP violation reports are token-less browser beacons — exempt the
        // collector route from CSRF (Task A3).
        $middleware->validateCsrfTokens(except: [
            'csp-report',
        ]);

        // Already-authenticated visitors hitting /login or /register go home;
        // the post-auth redirect rule then applies on their next real login.
        $middleware->redirectUsersTo('/');

        $middleware->alias([
            'exporter.onboarded' => EnsureExporterOnboarded::class,
            'buyer' => EnsureBuyerAccount::class,
            'api.buyer' => EnsureApiBuyer::class,
            'api.supplier' => EnsureApiSupplier::class,
            'demo.logins.enabled' => EnsureDemoLoginsEnabled::class,
            'requires.recent.2fa' => RequiresRecentTwoFactor::class,
            // Emits RFC 8594 Deprecation/Sunset/Link signalling on a route or
            // group. Not applied to any route today — see routes/api.php and
            // docs/api/CONVENTIONS.md for the "how to sunset an endpoint" flow.
            'deprecated' => AnnounceDeprecation::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Mirror every unhandled exception into the dedicated `errors` log
        // channel (production-readiness plan Task A2) so `ops:error-digest`
        // has an error-only file to summarise. Additive — the default stack
        // logging still runs. Wrapped in its own try/catch so a logging
        // failure can never mask or recurse on the original exception.
        $exceptions->report(function (\Throwable $e): void {
            try {
                $request = request();

                \Illuminate\Support\Facades\Log::channel('errors')->error(
                    $e::class.': '.$e->getMessage(),
                    [
                        'exception' => $e::class,
                        'path' => $request?->path(),
                        'method' => $request?->method(),
                        'user_id' => optional($request?->user())->getAuthIdentifier(),
                        'file' => $e->getFile().':'.$e->getLine(),
                        'trace' => collect(explode("\n", $e->getTraceAsString()))
                            ->take(8)
                            ->implode("\n"),
                    ],
                );
            } catch (\Throwable) {
                // Swallow — never let error reporting throw.
            }
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Standardised machine-readable error envelope for the JSON API
        // (GAPS.md §4). ONE shape for every error status:
        //   { "error": { "code", "message", "request_id", "details"? } }
        // Shaped in exactly one place — App\Exceptions\Api\ErrorEnvelope —
        // so controllers throw typed exceptions instead of hand-rolling
        // `response()->json(['message' => ...], $status)` bodies.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ErrorEnvelope::render($e, $request);
        });
    })->create();
