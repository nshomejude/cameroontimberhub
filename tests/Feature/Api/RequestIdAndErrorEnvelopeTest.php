<?php

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\ConflictException;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Request-id correlation (GAPS.md §6) + standardized error envelope (GAPS.md §4).
 *
 * A handful of throwaway routes under `api/v1/__test/*` exercise the renderer
 * for every branch without needing to construct the full domain state each
 * real 4xx/5xx would require; the real Api/V1 feature tests cover that the
 * genuine endpoints still hit the right status codes.
 */
beforeEach(function () {
    Route::middleware(\App\Http\Middleware\AssignRequestId::class)
        ->prefix('api/v1/__test')
        ->group(function () {
            Route::get('ok', fn () => response()->json(['data' => 'ok']));
            Route::get('conflict', fn () => throw new ConflictException('This quote is declined and can no longer be actioned.', 'quote_not_actionable'));
            Route::get('validation', fn () => throw new ApiException(422, 'validation_failed', 'The given data was invalid.', ['field' => ['The field is required.']]));
            Route::get('forbidden', fn () => abort(403, 'Nope.'));
            Route::get('missing', fn () => abort(404));
            Route::get('boom', fn () => throw new RuntimeException('DB password is hunter2'));
            Route::get('logs', function () {
                activity()->log('test-event');

                return response()->json(['data' => 'logged']);
            });
        });
});

/* ------------------------------------------------------------- request id */

it('echoes a well-formed inbound ULID request id and puts it in the error body', function () {
    $id = (string) Str::ulid();

    $response = $this->withHeader('X-Request-Id', $id)
        ->getJson('/api/v1/__test/conflict')
        ->assertStatus(409);

    expect($response->headers->get('X-Request-Id'))->toBe($id);
    $response->assertJsonPath('error.request_id', $id);
});

it('accepts a well-formed inbound UUID', function () {
    $id = (string) Str::uuid();

    $this->withHeader('X-Request-Id', $id)
        ->getJson('/api/v1/__test/ok')
        ->assertOk()
        ->assertHeader('X-Request-Id', $id);
});

it('generates a fresh ULID when no request id is sent', function () {
    $response = $this->getJson('/api/v1/__test/ok')->assertOk();

    $generated = $response->headers->get('X-Request-Id');

    expect($generated)->not->toBeNull()
        ->and(Str::isUlid($generated))->toBeTrue();
});

it('ignores a malformed inbound request id and generates a fresh one', function () {
    $response = $this->withHeader('X-Request-Id', 'not a valid id!! <script>')
        ->getJson('/api/v1/__test/ok')
        ->assertOk();

    $generated = $response->headers->get('X-Request-Id');

    expect($generated)->not->toBe('not a valid id!! <script>')
        ->and(Str::isUlid($generated))->toBeTrue();
});

it('stamps the request id into the activity log', function () {
    $id = (string) Str::ulid();

    $this->withHeader('X-Request-Id', $id)
        ->getJson('/api/v1/__test/logs')
        ->assertOk();

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['request_id'] ?? null)->toBe($id)
        ->and($activity->properties)->toHaveKey('ip');
});

it('stamps request context before the tamper-evident hash is computed', function () {
    // The stamper must merge its properties BEFORE ChainedActivity::booted()'s
    // `creating` hook hashes the row, or verify-chain would flag every
    // HTTP-triggered activity in production.
    $this->withHeader('X-Request-Id', (string) Str::ulid())
        ->getJson('/api/v1/__test/logs')
        ->assertOk();

    $this->artisan('activitylog:verify-chain')
        ->assertSuccessful()
        ->expectsOutputToContain('Chain intact');
});

/* --------------------------------------------------------- error envelope */

it('shapes a 409 as the standard envelope with a per-case code', function () {
    $this->getJson('/api/v1/__test/conflict')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'quote_not_actionable')
        ->assertJsonPath('error.message', 'This quote is declined and can no longer be actioned.')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
});

it('shapes a 422 with a details map', function () {
    $this->getJson('/api/v1/__test/validation')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.field.0', 'The field is required.')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id', 'details']]);
});

it('shapes a 403', function () {
    $this->getJson('/api/v1/__test/forbidden')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'forbidden')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
});

it('shapes a 404', function () {
    $this->getJson('/api/v1/__test/missing')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
});

it('shapes a 401 on a real guarded endpoint', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
});

it('shapes a 422 on a real endpoint (validation) under error.details', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrors('email', 'error.details');
});

it('shapes a 429 with rate_limited and keeps Retry-After', function () {
    foreach (range(1, 6) as $i) {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'x',
        ]);
    }

    $response->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
});

it('never leaks the exception message on a 500 when debug is off', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/__test/boom')->assertStatus(500);

    $response->assertJsonPath('error.code', 'server_error')
        ->assertJsonPath('error.message', 'An unexpected error occurred.')
        ->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);

    expect(json_encode($response->json()))->not->toContain('hunter2');
});
