<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('offers staff a continue-to-admin button after confirming 2FA', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $secret = $user->generateTwoFactorSecret();

    $this->actingAs($user)
        ->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertOk()
        ->assertSee('Continue to admin panel')
        ->assertSee(url('/admin'), false);
});

it('sends staff back to the intended admin page after confirming 2FA', function () {
    config(['auth.require_staff_2fa' => true]);
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)->get('/admin/users')
        ->assertRedirect(route('two-factor.show'));

    $this->get(route('two-factor.show'))
        ->assertSee('Two-factor authentication is required for staff accounts');

    $secret = $user->generateTwoFactorSecret();

    $this->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertSee(url('/admin/users'), false);
});

it('does not offer the admin button to non-staff', function () {
    $user = User::factory()->create();
    $secret = $user->generateTwoFactorSecret();

    $this->actingAs($user)
        ->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertOk()
        ->assertDontSee('Continue to admin panel');
});

it('refuses to let staff disable 2FA while it is required (web and API)', function () {
    config(['auth.require_staff_2fa' => true]);
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);
    $user->assignRole('support_officer');
    $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $this->actingAs($user)
        ->post(route('two-factor.disable'), ['password' => 'correct-password'])
        ->assertSessionHasErrors('password');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/two-factor/disable', ['password' => 'correct-password'])
        ->assertStatus(422);

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('lets staff disable 2FA when the requirement is off', function () {
    config(['auth.require_staff_2fa' => false]);
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);
    $user->assignRole('admin');
    $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $this->actingAs($user)
        ->post(route('two-factor.disable'), ['password' => 'correct-password'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('mentions the 2FA enrolment URL in admin:create output', function () {
    Artisan::call('admin:create', ['email' => 'boss@example.com', '--show-password' => true]);

    expect(Artisan::output())->toContain('/security/two-factor');
});

it('lets every staff role reach the admin panel without an account/admin loop', function (string $role) {
    config(['auth.require_staff_2fa' => false]);
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->canAccessPanel(filament()->getPanel('admin')))->toBeTrue();

    $this->actingAs($user)->get('/admin')->assertOk();
})->with(User::STAFF_ROLES);
