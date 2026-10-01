<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

it('seeds only reference data in production — no default admin, no demo companies', function () {
    app()->detectEnvironment(fn () => 'production');

    (new DatabaseSeeder)->setContainer(app())->__invoke();

    expect(User::count())->toBe(0)
        ->and(\App\Models\Company::count())->toBe(0)
        ->and(\Spatie\Permission\Models\Role::where('name', 'super_admin')->exists())->toBeTrue()
        ->and(\App\Models\Plan::count())->toBeGreaterThan(0);
});

it('refuses to run the demo seeder in production', function () {
    app()->detectEnvironment(fn () => 'production');

    (new DemoDataSeeder)->setContainer(app())->__invoke();
})->throws(RuntimeException::class);

it('admin:create creates a super_admin with a lowercased email and emails a reset link', function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->artisan('admin:create', ['email' => 'Ops@Example.CM', '--name' => 'Ops Lead'])
        ->assertSuccessful();

    $user = User::where('email', 'ops@example.cm')->firstOrFail();

    expect($user->hasRole('super_admin'))->toBeTrue()
        ->and($user->name)->toBe('Ops Lead')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('password', $user->password))->toBeFalse();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('admin:create --show-password prints a working one-time password and sends no email', function () {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->artisan('admin:create', ['email' => 'second@example.cm', '--show-password' => true])
        ->expectsOutputToContain('One-time password')
        ->assertSuccessful();

    expect(User::where('email', 'second@example.cm')->first()->hasRole('super_admin'))->toBeTrue();
    Notification::assertNothingSent();
});

it('admin:create promotes an existing user without touching their password', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create(['email' => 'existing@example.cm']);
    $hash = $user->password;

    $this->artisan('admin:create', ['email' => 'EXISTING@example.cm'])->assertSuccessful();

    expect($user->fresh()->hasRole('super_admin'))->toBeTrue()
        ->and($user->fresh()->password)->toBe($hash)
        ->and(User::count())->toBe(1);
});

it('admin:create fails clearly when roles are not seeded or the email is invalid', function () {
    $this->artisan('admin:create', ['email' => 'a@example.cm'])->assertFailed();

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->artisan('admin:create', ['email' => 'not-an-email'])->assertFailed();
});
