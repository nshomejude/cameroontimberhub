<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
