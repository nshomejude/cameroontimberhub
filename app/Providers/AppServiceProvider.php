<?php

namespace App\Providers;

use App\Models\Product;
use App\Observers\ProductObserver;
use App\Models\Order;
use App\Observers\OrderObserver;
use App\Models\CheckpointUpdate;
use App\Observers\ShipmentObserver;
use App\Models\CompanyDocument;
use App\Observers\CompanyDocumentObserver;
use App\Models\OrderDocument;
use App\Observers\OrderDocumentObserver;
use App\Policies\ActivityLogPolicy;
use App\Support\Bus\CommandBus;
use App\Support\Bus\QueryBus;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CommandBus::class);
        $this->app->singleton(QueryBus::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Activity::class, ActivityLogPolicy::class);

        Product::observe(ProductObserver::class);

        $this->injectRequestContextIntoAuditLog();
        $this->registerRateLimiters();

        Order::observe(OrderObserver::class);

        // Shipment carries no status column of its own — its milestones are
        // CheckpointUpdate rows recorded against it (see app/Observers/ShipmentObserver.php
        // doc block), so the observer is registered on CheckpointUpdate.
        CheckpointUpdate::observe(ShipmentObserver::class);

        // AI-assisted document extraction (blueprint §35) — queued, never
        // blocking the upload; see the observers' own try/catch.
        CompanyDocument::observe(CompanyDocumentObserver::class);
        OrderDocument::observe(OrderDocumentObserver::class);
    }

    /** Public-intake rate limiters (spec §4.2), keyed in Redis. */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('rfq-submit', fn (Request $request) => [
            Limit::perHour(5)->by('rfq-ip:'.$request->ip()),
            Limit::perHour(3)->by('rfq-email:'.strtolower((string) $request->input('buyer_email'))),
        ]);

        // Wizard step saves write only to the session, so they get their own,
        // much looser limiter — the strict rfq-submit budget above is reserved
        // for the one POST that actually creates an RFQ.
        RateLimiter::for('rfq-step', fn (Request $request) => Limit::perHour(120)->by('rfq-step-ip:'.$request->ip()));

        // Public receipt verification is open to anyone, so it is the one place
        // a stranger could grind receipt numbers. Budget is per-IP and tight
        // enough that guessing a 5-char Crockford suffix is hopeless.
        RateLimiter::for('receipt-verify', fn (Request $request) => [
            Limit::perMinute(10)->by('receipt-verify-ip:'.$request->ip()),
            Limit::perHour(60)->by('receipt-verify-ip-hour:'.$request->ip()),
        ]);

        // Public certificate verification, same reasoning as receipt-verify
        // above: open to anyone, so it is the one place a stranger could
        // grind verification tokens.
        RateLimiter::for('certificate-verify', fn (Request $request) => [
            Limit::perMinute(10)->by('certificate-verify-ip:'.$request->ip()),
            Limit::perHour(60)->by('certificate-verify-ip-hour:'.$request->ip()),
        ]);

        // Public checkpoint tracking, same reasoning as receipt-verify /
        // certificate-verify: open to anyone holding the link, so budgeted
        // per-IP against token-grinding.
        RateLimiter::for('checkpoint-track', fn (Request $request) => [
            Limit::perMinute(10)->by('checkpoint-track-ip:'.$request->ip()),
            Limit::perHour(60)->by('checkpoint-track-ip-hour:'.$request->ip()),
        ]);

        // Field checkpoint recording (blueprint §45-46). Looser than
        // checkpoint-track above: a legitimate driver's OfflineQueue can
        // burst-flush several queued checkpoints at once after being
        // offline for a while, so this budgets generously per-IP rather
        // than per-request while still bounding abuse of a leaked waybill
        // link.
        RateLimiter::for('checkpoint-record', fn (Request $request) => [
            Limit::perMinute(20)->by('checkpoint-record-ip:'.$request->ip()),
            Limit::perHour(200)->by('checkpoint-record-ip-hour:'.$request->ip()),
        ]);

        // One-click demo logins. Nobody legitimately needs more than a handful
        // a minute, and the budget blunts a script cycling demo sessions to
        // farm CSRF-valid authenticated sessions.
        RateLimiter::for('demo-login', fn (Request $request) => [
            Limit::perMinute(6)->by('demo-login-ip:'.$request->ip()),
            Limit::perHour(30)->by('demo-login-ip-hour:'.$request->ip()),
        ]);

        RateLimiter::for('inquiry-submit', fn (Request $request) => Limit::perHour(8)->by('inquiry-ip:'.$request->ip()));

        // Content-Security-Policy violation beacons (Task A3). Unauthenticated
        // by necessity; browsers batch and can burst several reports per page
        // load, so the budget is deliberately generous — it exists only to cap
        // a flood, not to shape legitimate traffic.
        RateLimiter::for('csp-report', fn (Request $request) => Limit::perMinute(60)->by('csp-report-ip:'.$request->ip()));

        // Session-only public writes (locale switch, RFQ shortlist add/remove).
        // These mutate the visitor's own session and nothing else, so they were
        // previously unthrottled; this generous per-IP cap just blunts a scripted
        // flood without ever getting in a real visitor's way.
        RateLimiter::for('session-write', fn (Request $request) => Limit::perMinute(60)->by('session-write-ip:'.$request->ip()));

        // "Notify me when the mobile app ships". One address is all anyone
        // needs; the short burst allowance just covers a typo and a re-submit.
        RateLimiter::for('app-notify', fn (Request $request) => [
            Limit::perMinute(4)->by('app-notify-ip:'.$request->ip()),
            Limit::perHour(15)->by('app-notify-ip-hour:'.$request->ip()),
        ]);

        // Messaging is authenticated, so the budget is per-account rather than
        // per-IP: generous enough for a real negotiation, tight enough that a
        // compromised account cannot firehose a supplier's inbox. Applied to
        // the HTTP fallback route and re-checked inside the Livewire composer,
        // which bypasses route middleware.
        RateLimiter::for('message-send', fn (Request $request) => [
            Limit::perMinute(20)->by('msg-send:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perHour(300)->by('msg-send-hour:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);

        // Opening threads is rarer than posting in one, and each new thread
        // notifies a supplier, so it gets a much smaller budget.
        RateLimiter::for('message-start', fn (Request $request) => Limit::perHour(30)
            ->by('msg-start:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // In-thread commerce. Tighter than prose because each action writes to
        // the RFQ/quote/order tables and, for an RFQ, sends mail. Accepting is
        // as cheap as a click, so the budget also exists to blunt a stolen
        // session hammering accept against every open quote. Applied to the
        // plain routes and re-checked inside the Livewire actions, which do not
        // pass through route middleware.
        RateLimiter::for('chat-rfq', fn (Request $request) => Limit::perHour(12)
            ->by('chat-rfq:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('chat-decision', fn (Request $request) => [
            Limit::perMinute(10)->by('chat-decision:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perHour(120)->by('chat-decision-hour:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);

        // Document uploads cost disk, not just rows, so they get a tighter
        // budget than the rest of the lifecycle actions.
        RateLimiter::for('order-upload', fn (Request $request) => [
            Limit::perMinute(6)->by('order-upload:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perHour(60)->by('order-upload-hour:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);

        // A buyer can leave at most one review per order and the database
        // enforces it; this budget exists only to blunt a scripted attempt to
        // find that out by brute force.
        RateLimiter::for('order-review', fn (Request $request) => Limit::perMinute(5)
            ->by('order-review:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // A reorder writes an RFQ, a routing row, a lead and a card, and sends
        // the verification mail — the same cost profile as `chat-rfq`, so the
        // same hourly budget. The database's partial unique index is what makes
        // a double submission harmless; this only stops a grind.
        RateLimiter::for('chat-reorder', fn (Request $request) => Limit::perHour(12)
            ->by('chat-reorder:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        $this->registerApiRateLimiters();
    }

    /**
     * Mobile API limiters. Deliberately the same shape as their web
     * counterparts — a native client is not a reason to relax a budget.
     *
     * Auth endpoints are keyed per-IP *and* per-address so a credential-stuffing
     * run cannot spread across addresses from one host, nor grind one account
     * from many hosts. `api-rfq` mirrors `rfq-submit` exactly: an RFQ costs a
     * row, a risk evaluation and an outbound mail whichever client raised it.
     */
    protected function registerApiRateLimiters(): void
    {
        RateLimiter::for('api-login', fn (Request $request) => [
            Limit::perMinute(5)->by('api-login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('api-login-email:'.strtolower((string) $request->input('email'))),
            Limit::perHour(30)->by('api-login-ip-hour:'.$request->ip()),
        ]);

        RateLimiter::for('api-register', fn (Request $request) => [
            Limit::perHour(5)->by('api-register-ip:'.$request->ip()),
            Limit::perDay(10)->by('api-register-ip-day:'.$request->ip()),
        ]);

        RateLimiter::for('api-rfq', fn (Request $request) => [
            Limit::perHour(5)->by('api-rfq-ip:'.$request->ip()),
            Limit::perHour(3)->by('api-rfq-user:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);

        // Re-sending a verification link costs an outbound mail to an address
        // the platform has not yet proven it owns, so the budget is tighter
        // than RFQ creation itself and keyed per RFQ as well as per account:
        // one buyer cannot spend the whole allowance on a single reference.
        RateLimiter::for('api-rfq-verify', fn (Request $request) => [
            Limit::perHour(3)->by('api-rfq-verify-rfq:'.$request->route('reference')),
            Limit::perHour(10)->by('api-rfq-verify-user:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perHour(20)->by('api-rfq-verify-ip:'.$request->ip()),
        ]);

        // Accepting or declining is one click, so the budget exists mainly to
        // blunt a stolen token hammering every open quote on an account.
        RateLimiter::for('api-decision', fn (Request $request) => [
            Limit::perMinute(10)->by('api-decision:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perHour(120)->by('api-decision-hour:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);

        // Per-API-key rate limiting (API-First plan Task 0.4). Keyed by the
        // Sanctum token's own id — NOT the user or IP — so two keys issued
        // to the same company user each get their own independent budget,
        // and a key survives being used from many IPs/devices without
        // fragmenting its budget. Falls back to per-IP for unauthenticated
        // requests (the public catalogue routes in routes/api.php).
        //
        // Tier RESOLUTION precedence (GAPS.md §7 — Plan→tier wiring at runtime):
        //   1. the owning company's CURRENT active plan's apiRateLimitTier()
        //      (company resolved via the token's ApiKeyMeta; active plan via
        //      Company::activeSubscription — the same seam ApproveApiKeyIssuance
        //      uses at issuance time, so runtime and issuance never diverge);
        //   2. the explicit ApiKeyMeta.rate_limit_tier when the token has a
        //      companion row but no resolvable plan (manually-tiered partner
        //      keys with no subscription keep working);
        //   3. config('api.rate_limit_tiers.default') otherwise;
        //   4. unauthenticated (no token) — unchanged, 60/min/IP.
        // The tier→per-minute mapping below is unchanged. Resolution is wrapped
        // in a try/catch: an exception thrown inside a limiter closure 500s
        // every API request, so any error falls through to the config default
        // with a Log::warning. No caching: Laravel resolves a named limiter's
        // Limit once per request, so this adds at most one indexed lookup +
        // one eager-load per request.
        RateLimiter::for('api-key', function (Request $request) {
            $token = $request->user()?->currentAccessToken();

            if (! $token) {
                return Limit::perMinute(60)->by('api-key-ip:'.$request->ip());
            }

            $tier = $this->resolveApiKeyRateLimitTier((int) $token->getKey());

            return Limit::perMinute($this->apiKeyTierToPerMinute($tier))->by('api-key:'.$token->getKey());
        });
    }

    /**
     * Resolve the rate-limit tier for a Sanctum token id, per GAPS.md §7
     * precedence. Never throws — any failure logs and returns the config
     * default tier.
     */
    private function resolveApiKeyRateLimitTier(int $tokenId): string
    {
        $default = (string) config('api.rate_limit_tiers.default', 'basic');

        try {
            $meta = \App\Models\ApiKeyMeta::query()
                ->where('personal_access_token_id', $tokenId)
                ->first(['company_id', 'rate_limit_tier']);

            $planTier = $meta?->company?->activeSubscription?->plan?->apiRateLimitTier();

            $tier = $planTier
                ?? ($meta?->rate_limit_tier)
                ?? $default;
        } catch (\Throwable $e) {
            Log::warning('api-key rate-limit tier resolution failed; using config default', [
                'token_id' => $tokenId,
                'exception' => $e->getMessage(),
            ]);

            $tier = $default;
        }

        return (string) $tier;
    }

    private function apiKeyTierToPerMinute(string $tier): int
    {
        return match ($tier) {
            'elevated' => 300,
            'basic' => 30,
            default => 60,
        };
    }

    /**
     * Audit trail (spec decision L): stamp every activity-log entry with the
     * request IP and user agent inside the `properties` JSONB — no extra
     * columns on the activity_log table. Skipped in console/queue contexts
     * where there is no real HTTP request.
     */
    protected function injectRequestContextIntoAuditLog(): void
    {
        // Bind to the *configured* activity model (App\Models\ChainedActivity),
        // not the base Spatie class — Eloquent keys model events by concrete
        // class, so a listener on Activity::class never fires for the subclass
        // rows the app actually writes. Registered from boot(), so it runs
        // before ChainedActivity::booted()'s own `creating` hook and the
        // tamper-evident hash therefore covers the stamped properties too.
        $activityModel = config('activitylog.activity_model') ?: Activity::class;

        $activityModel::creating(function (Activity $activity): void {
            // Request-correlation id (GAPS.md §6), set by AssignRequestId on the
            // web/api middleware stack. Its presence is also the signal that we
            // are inside a real HTTP request: a genuine console/queue context
            // never runs that middleware, so it has no request id and we skip
            // stamping request context entirely (the old `runningInConsole()`
            // gate mis-fired under the test runner, where SAPI is CLI but the
            // HTTP kernel — and this middleware — did run).
            $request = $this->app['request'];
            $requestId = $request->attributes->get('request_id') ?? Context::get('request_id');

            if ($this->app->runningInConsole() && ! is_string($requestId)) {
                return;
            }

            $properties = $activity->properties ?? collect();

            if ($properties instanceof Collection) {
                $context = [
                    'ip' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                ];

                if (is_string($requestId) && $requestId !== '') {
                    $context['request_id'] = $requestId;
                }

                $activity->properties = $properties->merge($context);
            }
        });
    }
}
