<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/* --------------------------------------------------------- enable/confirm */

it('reports two-factor as disabled by default', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/two-factor')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.confirmed_at', null);
});

it('enables two-factor and returns a secret plus otpauth url', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/two-factor/enable')
        ->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'otpauth_url', 'qr_svg']]);

    $user->refresh();
    expect($user->two_factor_secret)->toBe($response->json('data.secret'));
    expect($user->hasTwoFactorEnabled())->toBeFalse();
});

it('confirms two-factor with a correct code and issues recovery codes', function () {
    $user = User::factory()->create();
    $secret = $user->generateTwoFactorSecret();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code])
        ->assertCreated()
        ->assertJsonStructure(['data' => ['recovery_codes']]);

    expect($response->json('data.recovery_codes'))->toHaveCount(8);
    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/two-factor')
        ->assertOk()
        ->assertJsonPath('data.enabled', true);
});

it('rejects confirming two-factor with an incorrect code', function () {
    $user = User::factory()->create();
    $user->generateTwoFactorSecret();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code', 'error.details');

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('rejects confirming two-factor with no pending setup', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/two-factor/confirm', ['code' => '123456'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code', 'error.details');
});

/* ------------------------------------------------------------- disable */

it('disables two-factor with the correct password', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);
    $secret = $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/two-factor/disable', ['password' => 'correct-horse-battery-staple'])
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('disables two-factor with a valid TOTP code instead of the password', function () {
    $user = User::factory()->create();
    $secret = $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/two-factor/disable', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('data.enabled', false);
});

it('rejects disabling two-factor with a wrong password and no code', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);
    $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/two-factor/disable', ['password' => 'wrong'])
        ->assertStatus(422);

    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

/* ------------------------------------------------------------------ login */

it('returns two_factor_required instead of a token when 2FA is confirmed', function () {
    $user = User::factory()->create(['email' => 'twofa@example.com', 'password' => 'correct-horse-battery-staple']);
    $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'twofa@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertOk();

    expect($response->json('data.two_factor_required'))->toBeTrue();
    expect($response->json('data.challenge_token'))->not->toBeEmpty();
    expect($response->json('data.token'))->toBeNull();
});

it('logs in normally (no challenge) when 2FA is not confirmed', function () {
    $user = User::factory()->create(['email' => 'nofa@example.com', 'password' => 'correct-horse-battery-staple']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'nofa@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user']])
        ->assertJsonMissingPath('data.two_factor_required');
});

/* --------------------------------------------------------------- challenge */

function twoFactorLoginChallenge(): array
{
    $user = User::factory()->create(['email' => 'challenge@example.com', 'password' => 'correct-horse-battery-staple']);
    $secret = $user->generateTwoFactorSecret();
    $codes = $user->confirmTwoFactor();

    $challengeToken = app()->call(function () use ($user) {
        return \App\Http\Controllers\Api\V1\TwoFactorController::issueChallengeToken($user);
    });

    return [$user, $secret, $codes, $challengeToken];
}

it('issues a token when the challenge code is correct', function () {
    [$user, $secret, , $challengeToken] = twoFactorLoginChallenge();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $challengeToken,
        'code' => $code,
    ])->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user']])
        ->assertJsonPath('data.user.email', 'challenge@example.com');
});

it('fails the challenge with a wrong code', function () {
    [, , , $challengeToken] = twoFactorLoginChallenge();

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $challengeToken,
        'code' => '000000',
    ])->assertStatus(422);
});

it('rejects an expired or unknown challenge token', function () {
    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => 'not-a-real-token',
        'code' => '123456',
    ])->assertStatus(422)->assertJsonValidationErrors('challenge_token', 'error.details');
});

it('cannot reuse a challenge token after it has been consumed', function () {
    [$user, $secret, , $challengeToken] = twoFactorLoginChallenge();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $challengeToken,
        'code' => $code,
    ])->assertOk();

    // Same token, same still-valid code — replay must fail: the token was
    // invalidated on first use.
    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $challengeToken,
        'code' => $code,
    ])->assertStatus(422)->assertJsonValidationErrors('challenge_token', 'error.details');
});

it('invalidates the challenge token even on a failed attempt (no brute force retries)', function () {
    [, , , $challengeToken] = twoFactorLoginChallenge();

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $challengeToken,
        'code' => '000000',
    ])->assertStatus(422);

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $challengeToken,
        'code' => '111111',
    ])->assertStatus(422)->assertJsonValidationErrors('challenge_token', 'error.details');
});

it('logs in via a recovery code and consumes it', function () {
    [$user, , $codes, $challengeToken] = twoFactorLoginChallenge();
    $recoveryCode = $codes[0];

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $challengeToken,
        'recovery_code' => $recoveryCode,
    ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);

    expect($user->refresh()->consumeRecoveryCode($recoveryCode))->toBeFalse();
});

it('requires a code or recovery_code on the challenge', function () {
    [, , , $challengeToken] = twoFactorLoginChallenge();

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'challenge_token' => $challengeToken,
    ])->assertStatus(422)->assertJsonValidationErrors('code', 'error.details');
});
