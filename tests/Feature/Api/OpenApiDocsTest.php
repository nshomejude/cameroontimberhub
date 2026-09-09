<?php

/*
 * Regression guard for Task 0.3 (blueprint §4 "API-First, API-as-a-Product"):
 * the generated OpenAPI baseline must keep covering every existing /api/v1
 * route. This does not re-verify route behaviour — only that the doc tooling
 * (dedoc/scramble) stays wired up and does not silently drop coverage as new
 * v1 routes are added.
 *
 * Reminder for maintainers: a generated TypeScript SDK (blueprint §4 "SDKs",
 * Phase 3+ nice-to-have) lives at sdks/typescript/api-types.ts, derived from
 * this same spec. If you add/change an /api/v1 route in a way that changes
 * this spec, regenerate it: see sdks/typescript/README.md
 * (`php artisan scramble:export --path=storage/app/openapi.json` then
 * `npm run sdk:generate`, verified with `npm run sdk:check-stale`). That
 * check is a standalone Node script, not part of this Pest suite, because
 * it needs the Node/npm toolchain rather than only PHP.
 */

use Illuminate\Support\Facades\Route;

function apiV1RouteCount(): int
{
    return collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1'))
        ->count();
}

it('serves the docs UI at GET /docs/api', function () {
    $this->get('/docs/api')->assertOk();
});

it('serves a valid OpenAPI 3.1 document at GET /docs/api.json', function () {
    $response = $this->getJson('/docs/api.json')->assertOk();

    $spec = $response->json();

    expect($spec)->toBeArray()
        ->and($spec['openapi'])->toStartWith('3.1')
        ->and($spec)->toHaveKeys(['info', 'paths'])
        ->and($spec['paths'])->toBeArray()->not->toBeEmpty();
});

it('documents at least as many paths as v1 routes actually registered', function () {
    $spec = $this->getJson('/docs/api.json')->assertOk()->json();

    // Scramble groups per URI (not per HTTP verb), so compare distinct
    // route URIs under api/v1 to distinct documented paths — a route
    // silently dropped from the spec fails this before it fails anything
    // else.
    $registeredUris = collect(Route::getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1'))
        ->map(fn ($route) => '/'.preg_replace('#^api/v1/?#', '', $route->uri()))
        ->map(fn ($uri) => rtrim($uri, '/') ?: '/')
        ->unique()
        ->values();

    expect(apiV1RouteCount())->toBeGreaterThan(0)
        ->and(count($spec['paths']))->toBeGreaterThanOrEqual($registeredUris->count());
});

it('spot-checks that known v1 routes appear in the spec', function () {
    $spec = $this->getJson('/docs/api.json')->assertOk()->json();

    $paths = array_keys($spec['paths']);

    expect($paths)->toContain('/products')
        ->and($paths)->toContain('/rfqs')
        ->and($paths)->toContain('/quotes/{reference}/accept');

    expect($spec['paths']['/products'])->toHaveKey('get');
    expect($spec['paths']['/rfqs'])->toHaveKey('post');
    expect($spec['paths']['/quotes/{reference}/accept'])->toHaveKey('post');
});
