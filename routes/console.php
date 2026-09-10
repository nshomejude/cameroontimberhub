<?php

use App\Jobs\RelayOutboxEventsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Compliance daily maintenance.
Schedule::command('compliance:expire-badges')->dailyAt('06:30');
Schedule::command('compliance:remind-expiring')->dailyAt('07:00');

// Daily operational error digest (production-readiness plan Task A2): emails a
// count + top offenders from the `errors` log channel to config('mail.ops_address').
Schedule::command('ops:error-digest')->dailyAt('07:00')->withoutOverlapping();

// Queue-health monitor (production-readiness plan Task A4): alerts on the
// `errors` channel when failed_jobs grows or the oldest pending job / outbox
// relay is starving. Never gates — always exits 0.
Schedule::command('ops:queue-health')->everyFifteenMinutes()->withoutOverlapping();

// Transactional outbox relay (architecture plan, Task 0.2): publishes
// unpublished outbox_events rows by dispatching their matching domain event.
Schedule::job(new RelayOutboxEventsJob())->everyTenSeconds()->withoutOverlapping();
