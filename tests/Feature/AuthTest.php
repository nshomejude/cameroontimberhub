<?php

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lets a guest view the login page', function () {
    $this->get('/login')->assertOk()->assertSee('Log In', false);
});

it('lets a guest view the register page', function () {
    $this->get('/register')->assertOk()->assertSee('Create Your Account', false);
});

it('registers a buyer with no company and sends them to their account', function () {
    $response = $this->post('/register', [
        'account_type' => 'buyer',
        'name' => 'Bea Buyer',
        'email' => 'bea@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
    ]);

    $response->assertRedirect(route('account.index'));

    $user = User::where('email', 'bea@example.com')->firstOrFail();

    expect($user->companies()->exists())->toBeFalse();
    expect($user->password)->not->toBe('Str0ng-Passw0rd!');
    $this->assertAuthenticatedAs($user);
});

it('registers a supplier with a pending company and sends them to the dashboard', function () {
    $response = $this->post('/register', [
        'account_type' => 'supplier',
        'name' => 'Sam Supplier',
        'email' => 'sam@example.com',
        'company_name' => 'Douala Hardwoods SARL',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
    ]);

    $response->assertRedirect('/dashboard');

    $user = User::where('email', 'sam@example.com')->firstOrFail();
    $company = Company::where('legal_name', 'Douala Hardwoods SARL')->firstOrFail();

    expect($company->status)->toBe(CompanyStatus::Pending);
    expect($company->slug)->toBe('douala-hardwoods-sarl');
    expect($company->created_by)->toBe($user->id);
    expect($user->companies()->whereKey($company->id)->first()->pivot->role)->toBe('owner');
    $this->assertAuthenticatedAs($user);
});

it('requires a company name for supplier registration', function () {
    $this->post('/register', [
        'account_type' => 'supplier',
        'name' => 'Sam Supplier',
        'email' => 'sam@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
    ])->assertSessionHasErrors('company_name');

    expect(User::where('email', 'sam@example.com')->exists())->toBeFalse();
});

it('rejects a duplicate email on registration', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', [
        'account_type' => 'buyer',
        'name' => 'Dup',
        'email' => 'taken@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs a plain buyer in and redirects to their account', function () {
    $user = User::factory()->create([
        'email' => 'buyer@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ]);

    $this->post('/login', [
        'email' => 'buyer@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertRedirect(route('account.index'));

    $this->assertAuthenticatedAs($user);
});

it('sends a company member to the exporter dashboard on login', function () {
    $company = Company::factory()->create();
    $user = User::factory()->create([
        'email' => 'member@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ]);
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    $this->post('/login', [
        'email' => 'member@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertRedirect('/dashboard');
});

it('sends a staff user to the admin panel on login', function () {
    $user = User::factory()->create([
        'email' => 'staff@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ]);
    $user->assignRole('admin');
    $secret = $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    // Password alone is not enough for a 2FA account: no session yet.
    $this->post('/login', [
        'email' => 'staff@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertRedirect(route('login.two-factor'));
    $this->assertGuest();

    $code = app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('login.two-factor.store'), ['code' => $code])->assertRedirect('/admin');
    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong two-factor code at login and stays signed out', function () {
    $user = User::factory()->create(['email' => 'tf@example.com', 'password' => 'Str0ng-Passw0rd!']);
    $user->generateTwoFactorSecret();
    $user->confirmTwoFactor();

    $this->post('/login', ['email' => 'tf@example.com', 'password' => 'Str0ng-Passw0rd!'])
        ->assertRedirect(route('login.two-factor'));
    $this->get(route('login.two-factor'))->assertOk()->assertSee(route('login.two-factor.store'), false);

    $this->post(route('login.two-factor.store'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('accepts a recovery code to finish a two-factor login', function () {
    $user = User::factory()->create(['email' => 'rc@example.com', 'password' => 'Str0ng-Passw0rd!']);
    $user->generateTwoFactorSecret();
    $codes = $user->confirmTwoFactor();

    $this->post('/login', ['email' => 'rc@example.com', 'password' => 'Str0ng-Passw0rd!']);
    $this->post(route('login.two-factor.store'), ['code' => $codes[0]])->assertRedirect(route('account.index'));
    $this->assertAuthenticatedAs($user);
});

it('bounces the two-factor login step back to login when nothing is pending', function () {
    $this->get(route('login.two-factor'))->assertRedirect(route('login'));
    $this->post(route('login.two-factor.store'), ['code' => '123456'])->assertRedirect(route('login'));
    $this->assertGuest();
});

it('logs in regardless of email case and surrounding whitespace', function () {
    User::factory()->create(['email' => 'mixed@example.com', 'password' => 'Str0ng-Passw0rd!']);

    $this->post('/login', ['email' => '  Mixed@Example.COM ', 'password' => 'Str0ng-Passw0rd!'])
        ->assertRedirect(route('account.index'));
    $this->assertAuthenticated();
});

// Blueprint §39: admin-panel staff must have two-factor authentication
// configured; a login before that is done is redirected to set it up rather
// than into /admin.
it('sends an admin without two-factor configured to the two-factor setup screen instead of /admin', function () {
    config(['auth.require_staff_2fa' => true]);
    $user = User::factory()->create([
        'email' => 'staff-no-2fa@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ]);
    $user->assignRole('admin');

    $this->post('/login', [
        'email' => 'staff-no-2fa@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertRedirect(route('two-factor.show'));
});

it('rejects invalid credentials without starting a session', function () {
    User::factory()->create([
        'email' => 'buyer@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ]);

    $this->from('/login')->post('/login', [
        'email' => 'buyer@example.com',
        'password' => 'wrong-password',
    ])->assertRedirect('/login')->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('logs the user out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect(route('home'));

    $this->assertGuest();
});

it('redirects authenticated users away from the guest auth pages', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/login')->assertRedirect(route('home'));
    $this->actingAs($user)->get('/register')->assertRedirect(route('home'));
});
