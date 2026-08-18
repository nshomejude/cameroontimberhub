<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function validRegistration(array $overrides = []): array
{
    return array_merge([
        'account_type' => 'buyer',
        'name' => 'Bea Buyer',
        'email' => 'bea@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
    ], $overrides);
}

// ---------------------------------------------------------------- rendering

it('renders the login page for guests and keeps it out of the index', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Welcome Back', false)
        ->assertSee('name="robots" content="noindex, follow"', false);
});

it('renders the register page for guests and keeps it out of the index', function () {
    $this->get('/register')
        ->assertOk()
        ->assertSee('Create Your Account', false)
        ->assertSee('name="robots" content="noindex, follow"', false);
});

it('gives every auth input a real label, autocomplete and csrf token', function () {
    $login = $this->get('/login')->getContent();

    expect($login)->toContain('for="auth-email"')
        ->and($login)->toContain('id="auth-email"')
        ->and($login)->toContain('autocomplete="email"')
        ->and($login)->toContain('autocomplete="current-password"')
        ->and($login)->toContain('name="_token"');

    $register = $this->get('/register')->getContent();

    expect($register)->toContain('for="auth-name"')
        ->and($register)->toContain('autocomplete="name"')
        ->and($register)->toContain('autocomplete="organization"')
        ->and($register)->toContain('autocomplete="new-password"');
});

it('offers a real password visibility toggle button with an accessible name', function () {
    $html = $this->get('/login')->getContent();

    expect($html)->toContain('aria-pressed')
        ->and($html)->toContain('Show password');
});

it('links forgot password at a route that exists', function () {
    $this->get('/login')->assertSee(route('password.request'), false);
    $this->get('/forgot-password')->assertOk();
});

it('redirects authenticated users away from the guest auth pages', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/login')->assertRedirect(route('home'));
    $this->actingAs($user)->get('/register')->assertRedirect(route('home'));
    $this->actingAs($user)->get('/forgot-password')->assertRedirect(route('home'));
});

// ------------------------------------------------------------------- stats

it('shows a supplier count that comes from a real query', function () {
    $this->get('/login')->assertDontSee('data-stat="suppliers"', false);

    Company::factory()->publiclyVisible()->count(2)->create();

    $html = $this->get('/login')->getContent();
    expect($html)->toContain('data-stat="suppliers"')
        ->and($html)->toContain('>2</strong>');

    Company::factory()->publiclyVisible()->create();

    expect($this->get('/login')->getContent())->toContain('>3</strong>');
});

// ------------------------------------------------------------------- login

it('sends the user to the intended url and regenerates the session', function () {
    $user = User::factory()->create([
        'email' => 'buyer@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ]);

    $this->withSession(['url.intended' => url('/companies')]);
    $sessionId = session()->getId();

    $this->post('/login', [
        'email' => 'buyer@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertRedirect(url('/companies'));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($sessionId);
});

it('honours remember me', function () {
    $user = User::factory()->create([
        'email' => 'buyer@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ]);

    $this->post('/login', [
        'email' => 'buyer@example.com',
        'password' => 'Str0ng-Passw0rd!',
        'remember' => '1',
    ]);

    expect($user->fresh()->remember_token)->not->toBeNull();
});

it('gives the same generic error for a wrong password and an unknown email', function () {
    User::factory()->create([
        'email' => 'buyer@example.com',
        'password' => 'Str0ng-Passw0rd!',
    ]);

    $wrongPassword = $this->from('/login')->post('/login', [
        'email' => 'buyer@example.com',
        'password' => 'nope-nope-nope',
    ]);

    $unknownEmail = $this->from('/login')->post('/login', [
        'email' => 'nobody@example.com',
        'password' => 'nope-nope-nope',
    ]);

    $message = 'These credentials do not match our records.';

    $wrongPassword->assertSessionHasErrors(['email' => $message]);
    $unknownEmail->assertSessionHasErrors(['email' => $message]);

    $this->assertGuest();
});

it('never echoes the submitted password back into the form', function () {
    User::factory()->create(['email' => 'buyer@example.com', 'password' => 'Str0ng-Passw0rd!']);

    $this->from('/login')->post('/login', [
        'email' => 'buyer@example.com',
        'password' => 'sup3r-s3cret-typo',
    ]);

    $html = $this->followingRedirects()->get('/login')->getContent();

    expect($html)->not->toContain('sup3r-s3cret-typo')
        ->and($html)->toContain('buyer@example.com'); // other fields do keep old input
});

it('throttles repeated login attempts', function () {
    User::factory()->create(['email' => 'buyer@example.com', 'password' => 'Str0ng-Passw0rd!']);

    foreach (range(1, 6) as $ignored) {
        $this->post('/login', ['email' => 'buyer@example.com', 'password' => 'wrong'])
            ->assertStatus(302);
    }

    $this->post('/login', ['email' => 'buyer@example.com', 'password' => 'wrong'])
        ->assertStatus(429);
});

it('invalidates the session on logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    $sessionId = session()->getId();

    $this->post('/logout')->assertRedirect(route('home'));

    $this->assertGuest();
    expect(session()->getId())->not->toBe($sessionId);
});

// ---------------------------------------------------------------- register

it('creates a buyer with a hashed password and logs them in', function () {
    $this->post('/register', validRegistration())->assertRedirect(route('home'));

    $user = User::where('email', 'bea@example.com')->firstOrFail();

    expect($user->password)->not->toBe('Str0ng-Passw0rd!')
        ->and($user->companies()->exists())->toBeFalse();

    $this->assertAuthenticatedAs($user);
});

it('creates a pending company owned by a supplier, with the details they gave', function () {
    $this->post('/register', validRegistration([
        'account_type' => 'supplier',
        'email' => 'sam@example.com',
        'company_name' => 'Douala Hardwoods SARL',
        'company_phone' => '+237 690 00 00 00',
        'company_city' => 'Douala',
        'company_country' => 'CM',
        'company_registration_number' => 'RC/DLA/2020/B/123',
    ]))->assertRedirect('/dashboard');

    $user = User::where('email', 'sam@example.com')->firstOrFail();
    $company = Company::where('legal_name', 'Douala Hardwoods SARL')->firstOrFail();

    expect($company->phone)->toBe('+237 690 00 00 00')
        ->and($company->city)->toBe('Douala')
        ->and($company->country_code)->toBe('CM')
        ->and($company->registration_number)->toBe('RC/DLA/2020/B/123')
        ->and($user->companies()->whereKey($company->id)->first()->pivot->role)->toBe('owner');
});

it('rejects registration without agreeing to the terms', function () {
    $payload = validRegistration();
    unset($payload['terms']);

    $this->post('/register', $payload)->assertSessionHasErrors('terms');

    $this->assertGuest();
});

it('rejects a duplicate email, a weak password and missing fields', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/register', validRegistration(['email' => 'taken@example.com']))
        ->assertSessionHasErrors('email');

    $this->post('/register', validRegistration([
        'password' => 'short',
        'password_confirmation' => 'short',
    ]))->assertSessionHasErrors('password');

    $this->post('/register', validRegistration([
        'password_confirmation' => 'a-different-password',
    ]))->assertSessionHasErrors('password');

    $this->post('/register', [])->assertSessionHasErrors(['account_type', 'name', 'email', 'password', 'terms']);

    $this->assertGuest();
});

it('never echoes the submitted password back into the register form', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->from('/register')->post('/register', validRegistration([
        'email' => 'taken@example.com',
        'password' => 'my-l3aked-secret!',
        'password_confirmation' => 'my-l3aked-secret!',
    ]));

    $html = $this->followingRedirects()->get('/register')->getContent();

    expect($html)->not->toContain('my-l3aked-secret!')
        ->and($html)->toContain('taken@example.com');
});

// ---------------------------------------------------------- password reset

it('sends a reset link and gives the same answer for an unknown address', function () {
    Notification::fake();

    User::factory()->create(['email' => 'known@example.com']);

    $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'known@example.com']);
    $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'ghost@example.com']);

    $known->assertSessionHas('status');
    $unknown->assertSessionHas('status')
        ->assertSessionHasNoErrors();

    expect(session('status'))->toContain('If that email address matches an account');

    Notification::assertCount(1);
});

it('resets the password with a valid token and rejects an invalid one', function () {
    $user = User::factory()->create(['email' => 'known@example.com']);
    $token = Password::broker()->createToken($user);

    $this->get('/reset-password/'.$token)->assertOk();

    $this->post('/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'known@example.com',
        'password' => 'An0ther-Passw0rd!',
        'password_confirmation' => 'An0ther-Passw0rd!',
    ])->assertSessionHasErrors('email');

    $this->post('/reset-password', [
        'token' => $token,
        'email' => 'known@example.com',
        'password' => 'An0ther-Passw0rd!',
        'password_confirmation' => 'An0ther-Passw0rd!',
    ])->assertRedirect(route('login'));

    $this->post('/login', [
        'email' => 'known@example.com',
        'password' => 'An0ther-Passw0rd!',
    ]);

    $this->assertAuthenticatedAs($user->fresh());
});
