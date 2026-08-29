<?php

use App\Models\Company;
use App\Models\Driver;
use App\Models\Vehicle;

it('exposes vehicles and drivers relations on Company', function () {
    $company = Company::factory()->create();
    $vehicle = Vehicle::factory()->for($company)->create();
    $driver = Driver::factory()->for($company)->create();

    expect($company->vehicles->pluck('id'))->toContain($vehicle->id);
    expect($company->drivers->pluck('id'))->toContain($driver->id);
});
