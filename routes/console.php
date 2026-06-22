<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Compliance daily maintenance.
Schedule::command('compliance:expire-badges')->dailyAt('06:30');
Schedule::command('compliance:remind-expiring')->dailyAt('07:00');
