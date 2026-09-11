<?php

use App\Jobs\RelayOutboxEventsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Receipt integrity hash-chain verification (production-readiness plan Task
// B3): walks the chain in issue order and alerts on the first tampered row.
Schedule::command('receipts:verify-chain')->daily();

// Invoice + credit-note integrity hash-chain verification (billing engine
// M4): same discipline as receipts — alerts on the first tampered row.
Schedule::command('invoices:verify-chain')->daily();

// Pull-model subscription renewals (billing engine M6, §7.5): renewal
// reminders at renews_at − 7d, past-due + 7-day grace at renews_at, lapse to
// the segment Free plan at end of grace (and for unpaid trials). Idempotent.
Schedule::command('subscriptions:process-renewals')->dailyAt('02:30')->withoutOverlapping();

// 30-day advance notice of scheduled subscription price changes (billing
// engine M6). Stub until price versioning (M9) lands.
Schedule::command('subscriptions:notify-price-changes')->dailyAt('08:00');

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

// Reputation recompute (production-readiness plan Task C1): rebuilds every
// trading company's reputation figures from real order / RFQ / dispute rows.
Schedule::command('reputation:recompute')->dailyAt('03:00');

// Transactional outbox relay (architecture plan, Task 0.2): publishes
// unpublished outbox_events rows by dispatching their matching domain event.
Schedule::job(new RelayOutboxEventsJob())->everyTenSeconds()->withoutOverlapping();
