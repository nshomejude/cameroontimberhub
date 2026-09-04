<?php

use App\Http\Middleware\RequiresRecentTwoFactor;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('generates an unconfirmed secret when enabling two-factor', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('two-factor.enable'))
        ->assertRedirect(route('two-factor.show'));

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull();
    expect($user->two_factor_confirmed_at)->toBeNull();
    expect($user->hasTwoFactorEnabled())->toBeFalse();
});

it('confirms two-factor with a valid TOTP code and issues recovery codes', function () {
    $user = User::factory()->create();
    $secret = $user->generateTwoFactorSecret();

    $validCode = app(Google2FA::class)->getCurrentOtp($secret);

    $response = $this->actingAs($user)
        ->post(route('two-factor.confirm'), ['code' => $validCode]);

    $response->assertOk();
    $response->assertSee('Save your recovery codes');

    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeTrue();
    expect($user->two_factor_confirmed_at)->not->toBeNull();
});

it('rejects an invalid TOTP code on confirm', function () {
    $user = User::factory()->create();
    $user->generateTwoFactorSecret();

    $this->actingAs($user)
        ->post(route('two-factor.confirm'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $user->refresh();

    expect($user->hasTwoFactorEnabled())->toBeFalse();
});

it('disables two-factor with the correct current password', function () {
    $user = User::factory()->create(['password' => bcrypt('correct-password')]);
    $secret = $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $this->actingAs($user)
        ->post(route('two-factor.disable'), ['password' => 'correct-password'])
        ->assertRedirect(route('two-factor.show'));

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('blocks the step-up middleware for a user without a recent two-factor confirmation', function () {
    Route::middleware(['web', 'auth', RequiresRecentTwoFactor::class])
        ->get('/__test/step-up', fn () => 'ok')
        ->name('__test.step-up');

    $user = User::factory()->create();
    $secret = $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $this->actingAs($user)
        ->get('/__test/step-up')
        ->assertRedirect(route('two-factor.challenge.show', ['redirect_to' => url('/__test/step-up')]));
});

it('allows the step-up middleware through after a recent two-factor confirmation', function () {
    Route::middleware(['web', 'auth', RequiresRecentTwoFactor::class])
        ->get('/__test/step-up-2', fn () => 'ok')
        ->name('__test.step-up-2');

    $user = User::factory()->create();
    $secret = $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $this->actingAs($user);

    $validCode = app(Google2FA::class)->getCurrentOtp($secret);
    $this->post(route('two-factor.challenge.store'), ['code' => $validCode])
        ->assertRedirect(route('two-factor.show'));

    $this->get('/__test/step-up-2')->assertOk()->assertSee('ok');
});

it('redirects a user with no two-factor enrolled at all to the setup screen', function () {
    Route::middleware(['web', 'auth', RequiresRecentTwoFactor::class])
        ->get('/__test/step-up-3', fn () => 'ok')
        ->name('__test.step-up-3');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/__test/step-up-3')
        ->assertRedirect(route('two-factor.show'));
});
