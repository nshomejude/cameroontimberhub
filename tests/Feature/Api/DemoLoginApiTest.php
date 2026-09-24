<?php

use App\Features\DemoLoginsEnabled;
use App\Models\Company;
use App\Models\Rfq;
use App\Models\Vehicle;
use Database\Seeders\DemoLoginSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Pennant\Feature;

/**
 * API mirror of tests/Feature/DemoLoginTest.php (the web one-click demo
 * logins), asserting the /api/v1 surface behaves identically: same kill
 * switch, same persona allow-list, same token shape as /auth/login.
 */
beforeEach(function () {
    Feature::activate(DemoLoginsEnabled::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DemoLoginSeeder::class);
});

/* ---------------------------------------------------------------- personas */

it('lists personas from config when enabled', function () {
    $response = $this->getJson('/api/v1/auth/demo-personas')->assertOk();

    $keys = collect($response->json('data'))->pluck('key')->all();

    expect($keys)->toEqualCanonicalizing(array_keys(config('demo.personas')))
        ->and($response->json('data.0'))->toHaveKeys(['key', 'label', 'description', 'icon']);
});

it('returns an empty persona list when disabled, never an error', function () {
    Feature::deactivate(DemoLoginsEnabled::class);

    $this->getJson('/api/v1/auth/demo-personas')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

/* ------------------------------------------------------------------- login */

it('logs in each persona with a valid token and matching seeded state', function (string $persona) {
    $response = $this->postJson("/api/v1/auth/demo-login/{$persona}")
        ->assertOk()
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'role']]]);

    expect($response->json('data.user.email'))->toBe(config("demo.personas.{$persona}.email"));

    // The token actually authenticates, same as /auth/login's.
    $this->withToken($response->json('data.token'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', config("demo.personas.{$persona}.email"));
})->with(['buyer', 'supplier', 'admin', 'logistics', 'pending_supplier']);

it('shows the logistics persona as a logistics company', function () {
    $response = $this->postJson('/api/v1/auth/demo-login/logistics')->assertOk();

    expect($response->json('data.user.company.type'))->toBe('logistics');
});

it('shows the pending_supplier persona as a pending company', function () {
    $response = $this->postJson('/api/v1/auth/demo-login/pending_supplier')->assertOk();

    expect($response->json('data.user.company.status'))->toBe('pending');
});

it('gives the demo logistics persona a non-empty fleet', function () {
    $token = $this->postJson('/api/v1/auth/demo-login/logistics')->json('data.token');

    $response = $this->withToken($token)->getJson('/api/v1/supplier/fleet/vehicles')->assertOk();

    expect($response->json('data'))->not->toBeEmpty();
});

it('shows the demo supplier the seeded routed RFQ', function () {
    $token = $this->postJson('/api/v1/auth/demo-login/supplier')->json('data.token');

    $response = $this->withToken($token)->getJson('/api/v1/supplier/rfqs')->assertOk();

    expect($response->json('data'))->not->toBeEmpty();
});

it('shows the demo buyer the seeded order', function () {
    $token = $this->postJson('/api/v1/auth/demo-login/buyer')->json('data.token');

    $response = $this->withToken($token)->getJson('/api/v1/orders')->assertOk();

    expect($response->json('data'))->not->toBeEmpty();
});

/* ----------------------------------------------------------- abuse/kill switch */

it('rejects an unknown persona key', function () {
    $this->postJson('/api/v1/auth/demo-login/superuser')->assertNotFound();
});

it('refuses demo login when disabled, even for a real persona', function () {
    Feature::deactivate(DemoLoginsEnabled::class);

    $this->postJson('/api/v1/auth/demo-login/buyer')->assertForbidden();
});

/* ------------------------------------------------------------- idempotency */

it('does not duplicate personas, companies or seeded RFQ/order when seeded twice', function () {
    $userCounts = collect(config('demo.personas'))
        ->map(fn ($persona) => \App\Models\User::where('email', $persona['email'])->count());

    $companyCount = Company::count();
    $rfqCount = Rfq::count();
    $vehicleCount = Vehicle::count();

    $this->seed(DemoLoginSeeder::class);

    foreach (config('demo.personas') as $key => $persona) {
        expect(\App\Models\User::where('email', $persona['email'])->count())->toBe(1);
    }

    expect(Company::count())->toBe($companyCount)
        ->and(Rfq::count())->toBe($rfqCount)
        ->and(Vehicle::count())->toBe($vehicleCount);
});
