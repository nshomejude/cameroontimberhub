<?php

use App\Features\DemoLoginsEnabled;
use Database\Seeders\DemoLoginSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DemoLoginSeeder::class);
});

it('blocks the route at the middleware layer when the flag is inactive, before any controller logic runs', function () {
    Feature::deactivate(DemoLoginsEnabled::class);

    $response = $this->post(route('demo.login', 'admin'));

    $response->assertNotFound();
    // If this had reached DemoLoginController::__invoke, the admin persona
    // (a genuine super_admin) would now be authenticated. It must not be.
    expect(auth()->check())->toBeFalse();
});

it('lets the route through to the controller when the flag is active', function () {
    Feature::activate(DemoLoginsEnabled::class);

    $response = $this->post(route('demo.login', 'buyer'));

    $response->assertRedirect('/account');
    expect(auth()->check())->toBeTrue();
});
