<?php

use App\Features\DemoLoginsEnabled;
use App\Models\User;
use App\Support\Ops\OpsProbes;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Pennant\Feature;

/** Puts the app into a launch-ready state; individual tests then break one thing. */
function makeLaunchReady(): void
{
    $dir = sys_get_temp_dir().'/launch-check-'.getmypid();
    @mkdir($dir);
    @touch($dir.'/signing-key.json');

    config([
        'app.debug' => false,
        'app.url' => 'https://www.cameroontimberhub.com',
        'mail.default' => 'smtp',
        'mail.from.address' => 'no-reply@cameroontimberhub.com',
        'session.secure' => true,
        'queue.default' => 'database',
        'demo.enabled' => false,
        'filesystems.links' => [$dir => $dir],
        'certificates.signing_key_path' => $dir.'/signing-key.json',
    ]);

    test()->seed(RolesAndPermissionsSeeder::class);
    test()->seed(PlanSeeder::class);
    User::factory()->create()->assignRole('super_admin');
    User::factory()->create()->assignRole('super_admin');
    OpsProbes::recordSchedulerHeartbeat();
}

it('passes when the environment is launch-ready', function () {
    makeLaunchReady();

    $this->artisan('launch:check')->assertSuccessful();
});

it('fails on each blocking misconfiguration', function (Closure $break, string $expected) {
    makeLaunchReady();
    $break();

    $this->artisan('launch:check')
        ->expectsOutputToContain($expected)
        ->assertFailed();
})->with([
    'debug on' => [fn () => config(['app.debug' => true]), 'APP_DEBUG'],
    'log mailer' => [fn () => config(['mail.default' => 'log']), 'MAIL_MAILER'],
    'placeholder from' => [fn () => config(['mail.from.address' => 'hello@example.com']), 'MAIL_FROM_ADDRESS'],
    'http url' => [fn () => config(['app.url' => 'http://cameroontimberhub.com']), 'APP_URL'],
    'insecure cookie' => [fn () => config(['session.secure' => null]), 'SESSION_SECURE_COOKIE'],
    'sync queue' => [fn () => config(['queue.default' => 'sync']), 'QUEUE_CONNECTION'],
    'demo config' => [fn () => config(['demo.enabled' => true]), 'DEMO_LOGINS_ENABLED'],
    'demo pennant row' => [fn () => Feature::activate(DemoLoginsEnabled::class), 'Pennant'],
    'demo admin' => [fn () => User::factory()->create(['email' => 'admin@cameroontimberhub.test']), 'demo super admin'],
    'no storage link' => [fn () => config(['filesystems.links' => ['/nonexistent/storage' => '/x']]), 'storage:link'],
    'no signing key' => [fn () => config(['certificates.signing_key_path' => '/nonexistent/key.json']), 'signing key'],
    'no plans' => [fn () => \App\Models\Plan::query()->delete(), 'plans'],
    'no super admin' => [fn () => User::query()->delete(), 'admin:create'],
]);

it('fails when roles are not seeded', function () {
    config(['app.debug' => false]);

    $this->artisan('launch:check')
        ->expectsOutputToContain('Roles/permissions are not seeded')
        ->assertFailed();
});

it('only warns (does not fail) about a stale scheduler heartbeat or no payment gateway', function () {
    makeLaunchReady();
    \Illuminate\Support\Facades\Cache::forget(OpsProbes::HEARTBEAT_KEY);

    $this->artisan('launch:check')
        ->expectsOutputToContain('Scheduler heartbeat')
        ->expectsOutputToContain('No payment gateway')
        ->assertSuccessful();
});
