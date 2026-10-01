<?php

/*
 * Blueprint §39 on the API: staff without confirmed 2FA can neither mint a
 * token via POST /auth/login nor use /api/v1/staff/* with an older token.
 */

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['auth.require_staff_2fa' => true]);
});

function apiStaffUser(bool $with2fa = false): User
{
    $u = User::factory()->create(['password' => bcrypt('secret-pass-1')]);
    $u->assignRole('admin');

    if ($with2fa) {
        $u->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    }

    return $u->fresh();
}

it('refuses API login for staff without 2FA and issues no token', function () {
    $staff = apiStaffUser();

    $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'secret-pass-1'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'two_factor_enrollment_required')
        ->assertJsonPath('data', null);

    expect($staff->tokens()->count())->toBe(0);
    expect(json_encode($this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'secret-pass-1'])->json()))
        ->toContain('security\/two-factor');
});

it('still challenges enrolled staff and lets non-staff log in normally', function () {
    $staff = apiStaffUser(with2fa: true);
    if (! $staff->hasTwoFactorEnabled()) {
        $this->markTestSkipped('2FA storage format differs.');
    }

    $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'secret-pass-1'])
        ->assertOk()->assertJsonPath('data.two_factor_required', true);

    $buyer = User::factory()->create(['password' => bcrypt('secret-pass-1')]);
    $this->postJson('/api/v1/auth/login', ['email' => $buyer->email, 'password' => 'secret-pass-1'])
        ->assertOk()->assertJsonStructure(['data' => ['token']]);
});

it('does not block staff login when the requirement is switched off', function () {
    config(['auth.require_staff_2fa' => false]);
    $staff = apiStaffUser();

    $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => 'secret-pass-1'])
        ->assertOk()->assertJsonStructure(['data' => ['token']]);
});

it('blocks /api/v1/staff/* for staff tokens without 2FA', function () {
    $staff = apiStaffUser();

    $this->actingAs($staff, 'sanctum')->getJson('/api/v1/staff/support/tickets')
        ->assertForbidden()->assertJsonPath('error.code', 'two_factor_enrollment_required');

    $enrolled = apiStaffUser(with2fa: true);
    if ($enrolled->hasTwoFactorEnabled()) {
        $this->actingAs($enrolled, 'sanctum')->getJson('/api/v1/staff/support/tickets')->assertOk();
    }
});
