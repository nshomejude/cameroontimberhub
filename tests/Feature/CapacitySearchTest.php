<?php

use App\Models\Capacity;
use App\Models\Company;

it('finds companies whose capacity satisfies a capability/quantity/period request', function () {
    $sufficient = Company::factory()->create(['legal_name' => 'Big Kiln Co']);
    Capacity::factory()->for($sufficient, 'owner')->create(['capability' => 'Kiln drying', 'quantity' => 200, 'period' => 'month']);

    $insufficient = Company::factory()->create(['legal_name' => 'Small Kiln Co']);
    Capacity::factory()->for($insufficient, 'owner')->create(['capability' => 'Kiln drying', 'quantity' => 50, 'period' => 'month']);

    $wrongPeriod = Company::factory()->create(['legal_name' => 'Weekly Kiln Co']);
    Capacity::factory()->for($wrongPeriod, 'owner')->create(['capability' => 'Kiln drying', 'quantity' => 500, 'period' => 'week']);

    $matches = Capacity::query()->matching('Kiln', 100, 'month')->with('owner')->get();

    expect($matches)->toHaveCount(1)
        ->and($matches->first()->owner->legal_name)->toBe('Big Kiln Co');
});
