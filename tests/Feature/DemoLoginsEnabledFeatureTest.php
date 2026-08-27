<?php

use App\Features\DemoLoginsEnabled;
use Illuminate\Support\Facades\Config;
use Laravel\Pennant\Feature;

it('defaults to the DEMO_LOGINS_ENABLED config value the first time it resolves', function () {
    Config::set('demo.enabled', true);

    expect(Feature::active(DemoLoginsEnabled::class))->toBeTrue();
});

it('defaults to false when config demo.enabled is false', function () {
    Config::set('demo.enabled', false);

    expect(Feature::active(DemoLoginsEnabled::class))->toBeFalse();
});

it('persists an explicit admin override independently of the config default', function () {
    Config::set('demo.enabled', false);

    // Resolve once so Pennant has a stored row, then override it — this is
    // the admin-configurable behaviour the `database` driver exists for:
    // the stored value wins over the config default from this point on.
    Feature::active(DemoLoginsEnabled::class);
    Feature::activate(DemoLoginsEnabled::class);

    expect(Feature::active(DemoLoginsEnabled::class))->toBeTrue();

    Feature::deactivate(DemoLoginsEnabled::class);

    expect(Feature::active(DemoLoginsEnabled::class))->toBeFalse();
});
