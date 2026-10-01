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
        'auth.require_staff_2fa' => true,
        'app.trusted_proxies' => '127.0.0.1,::1',
        'timber.signup.carbon_enabled' => false,
        'contact.inbox' => 'info@cameroontimberhub.com',
        'mail.ops_address' => 'ops@cameroontimberhub.com',
    ]);

    fakeHostProbe(systemctlInstalled: true, unitActive: true);

    test()->seed(RolesAndPermissionsSeeder::class);
    test()->seed(PlanSeeder::class);
    User::factory()->create()->assignRole('super_admin');
    User::factory()->create()->assignRole('super_admin');
    OpsProbes::recordSchedulerHeartbeat();
}

/** Fakes the `command -v systemctl` / `systemctl is-active timberhub-queue` host probe. */
function fakeHostProbe(bool $systemctlInstalled, bool $unitActive): void
{
    \Illuminate\Support\Facades\Process::fake(function ($process) use ($systemctlInstalled, $unitActive) {
        $command = implode(' ', (array) $process->command);

        if (str_contains($command, 'command -v')) {
            return \Illuminate\Support\Facades\Process::result(exitCode: $systemctlInstalled ? 0 : 1);
        }

        return $unitActive
            ? \Illuminate\Support\Facades\Process::result(output: "active\n")
            : \Illuminate\Support\Facades\Process::result(output: "inactive\n", exitCode: 3);
    });
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

it('fails in production when staff 2FA is not enforced', function () {
    makeLaunchReady();
    config(['auth.require_staff_2fa' => false]);
    app()['env'] = 'production';

    $this->artisan('launch:check')
        ->expectsOutputToContain('STAFF_REQUIRE_2FA')
        ->assertFailed();
});

it('does not fail outside production when staff 2FA is off', function () {
    makeLaunchReady();
    config(['auth.require_staff_2fa' => false]);

    $this->artisan('launch:check')->assertSuccessful();
});

it('warns (without failing) about each launch-setting concern', function (Closure $break, string $expected) {
    makeLaunchReady();
    $break();

    $this->artisan('launch:check')
        ->expectsOutputToContain($expected)
        ->assertSuccessful();
})->with([
    'admin without 2FA' => [fn () => User::factory()->create(['email' => 'no2fa@cameroontimberhub.com'])->assignRole('admin'), 'Admin [no2fa@cameroontimberhub.com] has not confirmed two-factor'],
    'blank trusted proxies' => [fn () => config(['app.trusted_proxies' => '']), 'TRUSTED_PROXIES is blank'],
    'carbon signup on' => [fn () => config(['timber.signup.carbon_enabled' => true]), 'SIGNUP_CARBON_ENABLED'],
    'blank contact inbox' => [fn () => config(['contact.inbox' => null]), 'CONTACT_INBOX'],
    'placeholder contact inbox' => [fn () => config(['contact.inbox' => 'info@cameroontimberhub.test']), 'CONTACT_INBOX'],
    'ops address falls back to from' => [fn () => config(['mail.ops_address' => 'no-reply@cameroontimberhub.com']), 'MAIL_OPS_ADDRESS'],
    'ops address placeholder' => [fn () => config(['mail.ops_address' => 'hello@example.com']), 'MAIL_OPS_ADDRESS'],
    'queue unit inactive' => [fn () => fakeHostProbe(systemctlInstalled: true, unitActive: false), 'timberhub-queue is [inactive]'],
]);

it('prints trusted proxies and the API terms setting, and stays quiet when everything is in place', function () {
    makeLaunchReady();
    foreach (User::role('super_admin')->get() as $admin) {
        $admin->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    }

    $this->artisan('launch:check')
        ->expectsOutputToContain('TRUSTED_PROXIES: [127.0.0.1,::1]')
        ->expectsOutputToContain('API_REQUIRE_TERMS_ACCEPTED: false')
        ->doesntExpectOutputToContain('two-factor')
        ->doesntExpectOutputToContain('CONTACT_INBOX')
        ->doesntExpectOutputToContain('MAIL_OPS_ADDRESS')
        ->doesntExpectOutputToContain('ct_unaccent')
        ->doesntExpectOutputToContain('timberhub-queue')
        ->assertSuccessful();
});

it('skips the systemd probe when systemctl is not installed', function () {
    makeLaunchReady();
    fakeHostProbe(systemctlInstalled: false, unitActive: false);

    $this->artisan('launch:check')
        ->doesntExpectOutputToContain('timberhub-queue is')
        ->assertSuccessful();
});
