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

        RateLimiter::for('inquiry-submit', fn (Request $request) => Limit::perHour(8)->by('inquiry-ip:'.$request->ip()));
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
