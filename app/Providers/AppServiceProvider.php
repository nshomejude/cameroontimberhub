<?php

namespace App\Providers;

use App\Policies\ActivityLogPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Activity::class, ActivityLogPolicy::class);

        $this->injectRequestContextIntoAuditLog();
        $this->registerRateLimiters();
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

        // One-click demo logins. Nobody legitimately needs more than a handful
        // a minute, and the budget blunts a script cycling demo sessions to
        // farm CSRF-valid authenticated sessions.
        RateLimiter::for('demo-login', fn (Request $request) => [
            Limit::perMinute(6)->by('demo-login-ip:'.$request->ip()),
            Limit::perHour(30)->by('demo-login-ip-hour:'.$request->ip()),
        ]);

        RateLimiter::for('inquiry-submit', fn (Request $request) => Limit::perHour(8)->by('inquiry-ip:'.$request->ip()));

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
    }

    /**
     * Audit trail (spec decision L): stamp every activity-log entry with the
     * request IP and user agent inside the `properties` JSONB — no extra
     * columns on the activity_log table. Skipped in console/queue contexts
     * where there is no real HTTP request.
     */
    protected function injectRequestContextIntoAuditLog(): void
    {
        Activity::creating(function (Activity $activity): void {
            if ($this->app->runningInConsole()) {
                return;
            }

            $request = $this->app['request'];
            $properties = $activity->properties ?? collect();

            if ($properties instanceof Collection) {
                $activity->properties = $properties->merge([
                    'ip' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                ]);
            }
        });
    }
}
