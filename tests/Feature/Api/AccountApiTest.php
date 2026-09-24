<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

/* ------------------------------------------------------------- PATCH me */

it('updates name, phone and locale for the authenticated user', function () {
    $user = User::factory()->create(['name' => 'Old Name', 'phone' => null, 'locale' => null]);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/auth/me', [
            'name' => 'New Name',
            'phone' => '+237600000000',
            'locale' => 'fr',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.phone', '+237600000000')
        ->assertJsonPath('data.locale', 'fr');

    expect($user->fresh())
        ->name->toBe('New Name')
        ->phone->toBe('+237600000000')
        ->locale->toBe('fr');
});

it('updates only the fields provided, leaving the rest untouched', function () {
    $user = User::factory()->create(['name' => 'Kept Name', 'phone' => '+237611111111', 'locale' => 'en']);

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/auth/me', ['locale' => 'es'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Kept Name')
        ->assertJsonPath('data.phone', '+237611111111')
        ->assertJsonPath('data.locale', 'es');
});

it('rejects an unsupported locale on PATCH me', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/v1/auth/me', ['locale' => 'xx'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('locale', 'error.details');
});

it('requires authentication for PATCH me', function () {
    $this->patchJson('/api/v1/auth/me', ['name' => 'Nope'])
        ->assertUnauthorized();
});

/* --------------------------------------------------------- POST password */

it('rejects a password change with the wrong current password', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery-staple')]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/password', [
            'current_password' => 'totally-wrong',
            'password' => 'new-correct-horse-battery',
            'password_confirmation' => 'new-correct-horse-battery',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('current_password', 'error.details');

    expect(Hash::check('correct-horse-battery-staple', $user->fresh()->password))->toBeTrue();
});

it('changes the password when the current password is correct, and the new password works on next login', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery-staple'), 'email' => 'change@example.com']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/password', [
            'current_password' => 'correct-horse-battery-staple',
            'password' => 'new-correct-horse-battery',
            'password_confirmation' => 'new-correct-horse-battery',
        ])
        ->assertNoContent();

    expect(Hash::check('new-correct-horse-battery', $user->fresh()->password))->toBeTrue();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'change@example.com',
        'password' => 'new-correct-horse-battery',
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);
});

it('leaves other devices logged in after a password change', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery-staple')]);
    $otherToken = $user->createToken('other-device')->plainTextToken;

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/auth/password', [
            'current_password' => 'correct-horse-battery-staple',
            'password' => 'new-correct-horse-battery',
            'password_confirmation' => 'new-correct-horse-battery',
        ])
        ->assertNoContent();

    $this->withToken($otherToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk();
});

/* ---------------------------------------------------- POST forgot-password */

it('returns an identical response for an existing and a nonexistent email', function () {
    User::factory()->create(['email' => 'exists@example.com']);

    $existing = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'exists@example.com'])
        ->assertOk();

    $missing = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])
        ->assertOk();

    expect($existing->json())->toBe($missing->json());
});

/* ----------------------------------------------------- POST reset-password */

it('resets the password with a real broker-issued token, and the old password stops working', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse-battery-staple'), 'email' => 'reset@example.com']);

    $token = Password::createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'reset@example.com',
        'password' => 'brand-new-password-123',
        'password_confirmation' => 'brand-new-password-123',
    ])->assertOk();

    expect(Hash::check('brand-new-password-123', $user->fresh()->password))->toBeTrue();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'reset@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertUnprocessable();
});

it('rejects a bogus reset token with the same message shape as the web flow', function () {
    $user = User::factory()->create(['email' => 'bogus@example.com']);

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'bogus@example.com',
        'password' => 'brand-new-password-123',
        'password_confirmation' => 'brand-new-password-123',
    ])->assertUnprocessable();

    // This app returns a standardized error envelope (error.code/message/details)
    // rather than Laravel's default {errors: {...}} shape — see
    // RequestIdAndErrorEnvelopeTest.
    expect($response->json('error.details.email.0'))->toBe('This password reset link is invalid or has expired.');
});
