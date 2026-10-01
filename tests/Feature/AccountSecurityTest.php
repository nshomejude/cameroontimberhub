<?php

use App\Http\Controllers\Auth\TwoFactorController;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Rfq;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Role;

/*
 * Account-creation & auth hardening: email verification (and the guest-RFQ
 * takeover it closes), staff 2FA enforcement, 2FA re-enrol guard, open
 * redirect, token revocation, terms consent, email canonicalisation.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function secRegistration(array $overrides = []): array
{
    return array_merge([
        'account_type' => 'buyer',
        'name' => 'Sec Buyer',
        'email' => 'sec@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
    ], $overrides);
}

function secVerifyUrl(User $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->getKey(),
        'hash' => $hash ?? sha1($user->email),
    ]);
}

/* ------------------------------------------------- email verification */

it('sends a verification email on web registration and stores a canonical email', function () {
    Notification::fake();

    $this->post('/register', secRegistration(['email' => '  Sec@Example.COM ']))->assertRedirect();

    $user = User::where('email', 'sec@example.com')->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->terms_accepted_at)->not->toBeNull()
        ->and($user->terms_version)->toBe(config('app.terms_version'));

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

it('rejects a registration whose email differs only by case from an existing account', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', secRegistration(['email' => 'TAKEN@example.com']))->assertSessionHasErrors('email');
});

it('does not hand a guest RFQ to an unverified registrant, then adopts it on verification', function () {
    Notification::fake();
    $rfq = Rfq::factory()->create(['buyer_email' => 'Victim@Example.com', 'user_id' => null]);

    $this->post('/register', secRegistration(['email' => 'victim@example.com']));
    $user = User::where('email', 'victim@example.com')->firstOrFail();

    expect($rfq->fresh()->user_id)->toBeNull();

    $this->actingAs($user)->get(secVerifyUrl($user))->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($rfq->fresh()->user_id)->toBe($user->getKey());
});

it('verifies from a signed link without a session but rejects a wrong hash or unsigned link', function () {
    $user = User::factory()->unverified()->create();

    $this->get(secVerifyUrl($user, sha1('other@example.com')))->assertForbidden();
    $this->get(route('verification.verify', ['id' => $user->getKey(), 'hash' => sha1($user->email)]))->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();

    $this->get(secVerifyUrl($user))->assertRedirect(route('login'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('resends the verification link on web and API', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post(route('verification.send'))->assertRedirect();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/email/verification-notification')
        ->assertStatus(202)->assertJsonPath('data.email_verified', false);

    Notification::assertSentToTimes($user, VerifyEmail::class, 2);
});

it('shows the verify-email banner to unverified buyers only', function () {
    $this->actingAs(User::factory()->unverified()->create())->get(route('account.index'))
        ->assertOk()->assertSee('verify-email-banner', false);
    $this->actingAs(User::factory()->create())->get(route('account.index'))
        ->assertOk()->assertDontSee('verify-email-banner', false);
});

it('lets unverified users sign in (no hard block)', function () {
    User::factory()->unverified()->create(['email' => 'unv@example.com', 'password' => 'Str0ng-Passw0rd!']);

    $this->post('/login', ['email' => 'unv@example.com', 'password' => 'Str0ng-Passw0rd!'])
        ->assertRedirect(route('account.index'));
    $this->assertAuthenticated();
});

it('requires a verified email to start a new conversation', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $buyer = User::factory()->unverified()->create();

    $this->actingAs($buyer)->post(route('account.messages.start'), ['company' => $company->slug])
        ->assertRedirect(route('verification.notice'));
    expect(Conversation::where('user_id', $buyer->getKey())->exists())->toBeFalse();

    $buyer->markEmailAsVerified();
    $this->actingAs($buyer)->post(route('account.messages.start'), ['company' => $company->slug])
        ->assertRedirect();
    expect(Conversation::where('user_id', $buyer->getKey())->exists())->toBeTrue();
});

/* ---------------------------------------------------- API registration */

it('requires and records terms acceptance on API registration', function () {
    $payload = ['name' => 'Api Buyer', 'email' => 'api@example.com', 'password' => 'correct-horse-battery-staple'];

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(422)->assertJsonValidationErrors('terms_accepted', 'error.details');

    Notification::fake();
    $this->postJson('/api/v1/auth/register', $payload + ['terms_accepted' => true])->assertCreated();

    $user = User::where('email', 'api@example.com')->firstOrFail();
    expect($user->terms_accepted_at)->not->toBeNull();
    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

it('creates a missing account role instead of failing registration', function () {
    Role::where('name', 'carbon_buyer')->delete();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $this->post('/register', secRegistration(['account_type' => 'carbon_buyer']))->assertRedirect();

    expect(User::where('email', 'sec@example.com')->firstOrFail()->hasRole('carbon_buyer'))->toBeTrue();
});

/* ------------------------------------------------------------- staff 2FA */

it('forces staff without 2FA to enrol before reaching /admin, and lets enrolled staff in', function () {
    config(['auth.require_staff_2fa' => true]);
    $staff = User::factory()->create();
    $staff->assignRole('compliance_officer');

    $this->actingAs($staff)->get('/admin')->assertRedirect(route('two-factor.show'));

    $staff->generateTwoFactorSecret();
    $staff->confirmTwoFactor();

    $this->actingAs($staff->fresh())->get('/admin')->assertOk();
});

it('derives every staff check from User::STAFF_ROLES', function () {
    expect(\App\Http\Middleware\EnsureBuyerAccount::STAFF_ROLES)->toBe(User::STAFF_ROLES);

    $staff = User::factory()->create();
    $staff->assignRole('moderator');

    // A moderator is staff everywhere, so is kept out of the buyer area.
    $this->actingAs($staff)->get(route('account.index'))->assertRedirect('/admin');
});

/* ------------------------------------------------------- 2FA management */

it('refuses to regenerate a confirmed 2FA secret without the current password (web)', function () {
    $user = User::factory()->create(['password' => 'Str0ng-Passw0rd!']);
    $secret = $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $this->actingAs($user)->post(route('two-factor.enable'))->assertSessionHasErrors('password');
    $this->actingAs($user)->post(route('two-factor.enable'), ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->fresh()->two_factor_secret)->toBe($secret);

    $this->actingAs($user)->post(route('two-factor.enable'), ['password' => 'Str0ng-Passw0rd!'])->assertRedirect();
    expect($user->fresh()->two_factor_secret)->not->toBe($secret);
});

it('refuses to regenerate a confirmed 2FA secret without the current password (API)', function () {
    $user = User::factory()->create(['password' => 'Str0ng-Passw0rd!']);
    $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/two-factor/enable')->assertStatus(422);
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/two-factor/enable', ['password' => 'nope'])->assertStatus(422);
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/two-factor/enable', ['password' => 'Str0ng-Passw0rd!'])->assertOk();
});

it('only allows same-site redirects after the step-up challenge', function () {
    $request = Request::create('https://hub.test/security/two-factor/challenge');
    $fallback = route('two-factor.show');

    expect(TwoFactorController::safeRedirect('/admin/companies', $request))->toBe('/admin/companies')
        ->and(TwoFactorController::safeRedirect('https://hub.test/admin', $request))->toBe('https://hub.test/admin')
        ->and(TwoFactorController::safeRedirect('https://evil.test/phish', $request))->toBe($fallback)
        ->and(TwoFactorController::safeRedirect('//evil.test/phish', $request))->toBe($fallback)
        ->and(TwoFactorController::safeRedirect('/\\evil.test', $request))->toBe($fallback)
        ->and(TwoFactorController::safeRedirect('javascript:alert(1)', $request))->toBe($fallback)
        ->and(TwoFactorController::safeRedirect(null, $request))->toBe($fallback);
});

it('does not redirect off-site after a successful step-up challenge', function () {
    $user = User::factory()->create();
    $secret = $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();
    $code = app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($user)->post(route('two-factor.challenge.store'), [
        'code' => $code,
        'redirect_to' => 'https://evil.test/phish',
    ])->assertRedirect(route('two-factor.show'));
});

/* ------------------------------------------------------ token revocation */

it('revokes every API token on a web password reset', function () {
    $user = User::factory()->create(['email' => 'reset@example.com']);
    $user->createToken('phone');
    $token = Password::createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => 'RESET@example.com',
        'password' => 'N3w-Str0ng-Passw0rd!',
        'password_confirmation' => 'N3w-Str0ng-Passw0rd!',
    ])->assertRedirect(route('login'));

    expect($user->tokens()->count())->toBe(0);
});

it('revokes every API token on an API password reset', function () {
    $user = User::factory()->create(['email' => 'reset2@example.com']);
    $user->createToken('phone');
    $token = Password::createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'reset2@example.com',
        'password' => 'N3w-Str0ng-Passw0rd!',
        'password_confirmation' => 'N3w-Str0ng-Passw0rd!',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(0);
});

it('revokes other API tokens but keeps the current one on an API password change', function () {
    $user = User::factory()->create(['password' => 'Old-Str0ng-Passw0rd!']);
    $user->createToken('tablet');
    $current = $user->createToken('phone')->plainTextToken;

    $this->withToken($current)->postJson('/api/v1/auth/password', [
        'current_password' => 'Old-Str0ng-Passw0rd!',
        'password' => 'N3w-Str0ng-Passw0rd!',
        'password_confirmation' => 'N3w-Str0ng-Passw0rd!',
    ])->assertNoContent();

    expect(PersonalAccessToken::where('tokenable_id', $user->getKey())->pluck('name')->all())->toBe(['phone']);
});

/* --------------------------------------------------------------- throttle */

it('throttles web login per email and IP', function () {
    User::factory()->create(['email' => 'grind@example.com']);

    foreach (range(1, 6) as $i) {
        $this->post('/login', ['email' => 'grind@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    $this->post('/login', ['email' => 'GRIND@example.com', 'password' => 'wrong'])->assertStatus(429);
    // A different address from the same IP is not locked out by that.
    $this->post('/login', ['email' => 'other@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
});
