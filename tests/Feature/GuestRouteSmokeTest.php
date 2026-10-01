<?php

use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Guest-flow smoke: every parameterless public GET web route must render
 * without a 500 for an anonymous visitor (lazy-loading violations, missing
 * eager loads, null relations on empty data, ...). Auth-only, signed,
 * admin/dashboard, API and framework/tooling routes are excluded.
 */
function guestSmokeRoutes(): array
{
    $excludedPrefixes = ['admin', 'dashboard', 'api', 'livewire', 'filament', 'sanctum', '_', 'horizon', 'telescope', 'pulse', 'storage', 'up'];

    return collect(Route::getRoutes()->getRoutes())
        ->filter(function (RoutingRoute $route) use ($excludedPrefixes): bool {
            if (! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []) {
                return false;
            }

            $uri = $route->uri();
            $first = explode('/', $uri)[0];
            if (collect($excludedPrefixes)->contains(fn ($p) => $first === $p || str_starts_with($first, '_'))) {
                return false;
            }

            $middleware = $route->gatherMiddleware();
            foreach ($middleware as $m) {
                if (is_string($m) && (str_starts_with($m, 'auth') || str_starts_with($m, 'signed') || str_starts_with($m, 'verified') || str_starts_with($m, 'can:') || str_starts_with($m, 'password.confirm'))) {
                    return false;
                }
            }

            return true;
        })
        ->map(fn (RoutingRoute $route) => '/'.ltrim($route->uri(), '/'))
        ->unique()
        ->values()
        ->all();
}

it('renders every public parameterless GET route for a guest without a server error', function () {
    // Realistic demo data so listing pages exercise their eager loads.
    $this->seed([RolesAndPermissionsSeeder::class, DemoDataSeeder::class]);
    User::factory()->create();
    Company::factory()->count(2)->create();

    $routes = guestSmokeRoutes();
    expect($routes)->not->toBeEmpty();

    $failures = [];
    foreach ($routes as $uri) {
        $status = $this->get($uri)->getStatusCode();
        if ($status >= 500) {
            $failures[] = "{$uri} => {$status}";
        }
    }

    expect($failures)->toBe([]);
});
