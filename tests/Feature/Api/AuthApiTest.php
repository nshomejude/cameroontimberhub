<?php

use App\Models\Company;
use App\Models\Rfq;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/* ------------------------------------------------------------- register */

it('registers a buyer and returns a usable token', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Amina Buyer',
        'email' => 'Amina@Example.com',
        'password' => 'correct-horse-battery-staple',
        'device_name' => 'pixel-8',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'amina@example.com')
        ->assertJsonPath('data.user.name', 'Amina Buyer')
        ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]]);

    $user = User::whereEmail('amina@example.com')->firstOrFail();

    expect($user->password)->not->toBe('correct-horse-battery-staple')
        ->and(Hash::check('correct-horse-battery-staple', $user->password))->toBeTrue()
        // Buyer path: no company created, no staff role.
        ->and($user->companies()->exists())->toBeFalse();

    // The token actually authenticates.
    $this->withToken($response->json('data.token'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'amina@example.com');
});

it('adopts account-free RFQs raised under the same address at registration', function () {
    $rfq = Rfq::factory()->create(['buyer_email' => 'legacy@example.com', 'user_id' => null]);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Legacy Buyer',
        'email' => 'legacy@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertCreated();

    expect($rfq->fresh()->user_id)->toBe(User::whereEmail('legacy@example.com')->value('id'));
});

it('never leaks the password hash or roles through the account payload', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();

    expect(array_keys($response->json('data')))
        ->toEqualCanonicalizing(['id', 'name', 'email', 'email_verified', 'email_verified_at', 'created_at']);
});

it('rejects a duplicate email with a 422', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Someone Else',
        'email' => 'taken@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects a weak password with a 422', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Weak Password',
        'email' => 'weak@example.com',
        'password' => 'abc',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});

/* ---------------------------------------------------------------- login */

it('logs a buyer in and issues a token', function () {
    User::factory()->create(['email' => 'buyer@example.com', 'password' => 'correct-horse-battery-staple']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'buyer@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
});

it('gives the same generic failure whether the address exists or not', function () {
    User::factory()->create(['email' => 'real@example.com', 'password' => 'correct-horse-battery-staple']);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'real@example.com',
        'password' => 'not-the-password',
    ])->assertStatus(422);

    $noSuchUser = $this->postJson('/api/v1/auth/login', [
        'email' => 'ghost@example.com',
        'password' => 'not-the-password',
    ])->assertStatus(422);

    expect($noSuchUser->json('errors'))->toBe($wrongPassword->json('errors'))
        ->and($noSuchUser->json('message'))->toBe($wrongPassword->json('message'))
        // Nothing in either body hints at which side failed.
        ->and(json_encode($noSuchUser->json()))->not->toContain('exist');
});

/* --------------------------------------------------------------- tokens */

it('revokes only the current token on logout and rejects it afterwards', function () {
    $user = User::factory()->create(['email' => 'multi@example.com', 'password' => 'correct-horse-battery-staple']);

    $phone = $this->postJson('/api/v1/auth/login', [
        'email' => 'multi@example.com', 'password' => 'correct-horse-battery-staple', 'device_name' => 'phone',
    ])->json('data.token');

    $tablet = $this->postJson('/api/v1/auth/login', [
        'email' => 'multi@example.com', 'password' => 'correct-horse-battery-staple', 'device_name' => 'tablet',
    ])->json('data.token');

    $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();

    // Revoked: gone from the database and refused at the door.
    expect(PersonalAccessToken::where('tokenable_id', $user->id)->count())->toBe(1);

    // The test kernel keeps one application instance across requests, so the
    // sanctum guard would hand back the user it already resolved. A real client
    // gets a fresh container per request; this reproduces that.
    $this->app['auth']->forgetGuards();

    $this->withToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $this->app['auth']->forgetGuards();

    $this->withToken($tablet)->getJson('/api/v1/auth/me')->assertOk();
});

it('refuses an unauthenticated call to a protected endpoint', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->getJson('/api/v1/rfqs')->assertUnauthorized();
});

/* ------------------------------------------------------------- throttle */

it('throttles repeated login attempts', function () {
    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'grind@example.com', 'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'grind@example.com', 'password' => 'wrong-password',
    ])->assertStatus(429);
});

it('throttles repeated registrations from one host', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/register', [
            'name' => "Buyer {$i}",
            'email' => "buyer{$i}@example.com",
            'password' => 'correct-horse-battery-staple',
        ])->assertCreated();
    }

    $this->postJson('/api/v1/auth/register', [
        'name' => 'One Too Many',
        'email' => 'toomany@example.com',
        'password' => 'correct-horse-battery-staple',
    ])->assertStatus(429);
});

/* ---------------------------------------------------- buyer-only surface */

it('keeps supplier and staff accounts out of the buyer commerce endpoints', function () {
    $supplier = User::factory()->create();
    $supplier->companies()->attach(Company::factory()->create(), ['role' => 'owner', 'is_primary' => true]);

    $this->actingAs($supplier, 'sanctum')->getJson('/api/v1/rfqs')->assertForbidden();

    $staff = User::factory()->create();
    $staff->assignRole('admin');

    $this->actingAs($staff, 'sanctum')->getJson('/api/v1/rfqs')->assertForbidden();
});
