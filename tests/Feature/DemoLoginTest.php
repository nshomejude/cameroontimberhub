<?php

use App\Models\User;
use Database\Seeders\DemoLoginSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Config;

/**
 * The demo-login feature signs a visitor in without a password, so every test
 * here is really about the blast radius: the kill switch must close both the
 * UI and the route, the persona must never be attacker-controlled, and a GET
 * must never authenticate anyone.
 */
beforeEach(function () {
    Config::set('demo.enabled', true);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DemoLoginSeeder::class);
});

it('signs in each persona and lands it on the right home screen', function (string $persona, string $target) {
    $this->post(route('demo.login', $persona))->assertRedirect($target);

    expect(auth()->check())->toBeTrue()
        ->and(auth()->user()->email)->toBe(config("demo.personas.{$persona}.email"));
})->with([
    'buyer' => ['buyer', '/account'],
    'supplier' => ['supplier', '/dashboard'],
    'admin' => ['admin', '/admin'],
]);

it('gives the demo supplier a company and the demo buyer none', function () {
    $supplier = User::where('email', config('demo.personas.supplier.email'))->firstOrFail();
    $buyer = User::where('email', config('demo.personas.buyer.email'))->firstOrFail();

    expect($supplier->companies()->exists())->toBeTrue()
        ->and($buyer->companies()->exists())->toBeFalse();
});

it('gives the demo admin a staff role', function () {
    $admin = User::where('email', config('demo.personas.admin.email'))->firstOrFail();

    expect($admin->hasRole('super_admin') || $admin->hasRole('admin'))->toBeTrue();
});

/* ------------------------------------------------------------- kill switch */

it('hides the buttons and refuses the route when disabled', function () {
    Config::set('demo.enabled', false);

    // The UI is hidden...
    $this->get('/login')->assertOk()->assertDontSee('Explore a demo account');

    // ...but hiding a control is not the boundary: the route itself refuses.
    $this->post(route('demo.login', 'admin'))->assertNotFound();

    expect(auth()->check())->toBeFalse();
});

it('renders the buttons when enabled', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Explore a demo account')
        ->assertSee('Demo Buyer')
        ->assertSee('Demo Supplier')
        ->assertSee('Demo Admin');
});

/* ------------------------------------------------------- abuse resistance */

it('rejects a persona outside the allow-list', function (string $persona) {
    $this->post('/demo-login/'.$persona)->assertNotFound();

    expect(auth()->check())->toBeFalse();
})->with([
    'an email' => ['admin@cameroontimberhub.test'],
    'traversal' => ['..%2Fadmin'],
    'unknown' => ['superuser'],
]);

it('never authenticates on a GET', function () {
    // A GET would let a plain link, a prefetch or a crawler log someone in.
    $this->get('/demo-login/admin')->assertMethodNotAllowed();

    expect(auth()->check())->toBeFalse();
});

it('regenerates the session on demo login', function () {
    $this->get('/login');
    $before = session()->getId();

    $this->post(route('demo.login', 'buyer'));

    expect(session()->getId())->not->toBe($before);
});

it('never exposes a demo password', function () {
    $response = $this->get('/login')->assertOk();

    // The seeded accounts carry strong random passwords; nothing should print
    // one, and no field should be pre-filled with credentials.
    $user = User::where('email', config('demo.personas.buyer.email'))->firstOrFail();

    expect($response->getContent())->not->toContain($user->password);
});

it('rate limits demo logins', function () {
    foreach (range(1, 6) as $ignored) {
        $this->post(route('demo.login', 'buyer'));
        auth()->logout();
    }

    $this->post(route('demo.login', 'buyer'))->assertStatus(429);
});

/* ------------------------------------------------------------- idempotency */

it('seeds exactly one account per persona when run twice', function () {
    $this->seed(DemoLoginSeeder::class);

    foreach (array_keys(config('demo.personas')) as $persona) {
        expect(User::where('email', config("demo.personas.{$persona}.email"))->count())->toBe(1);
    }
});
