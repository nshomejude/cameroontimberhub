<?php

use App\Models\Capacity;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('records a structured capacity entry for a company', function () {
    $company = Company::factory()->create();

    $capacity = Capacity::create([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'capability' => 'Kiln drying',
        'quantity' => 100,
        'unit' => 'm3',
        'period' => 'month',
    ]);

    expect($capacity->fresh())->not->toBeNull()
        ->and($capacity->owner)->toBeInstanceOf(Company::class)
        ->and($capacity->owner->is($company))->toBeTrue();
});

it('rejects a period value outside the allowed set via the CHECK constraint', function () {
    $company = Company::factory()->create();

    expect(fn () => DB::table('capacities')->insert([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'capability' => 'Kiln drying',
        'quantity' => 100,
        'unit' => 'm3',
        'period' => 'not_a_real_period',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('rejects a non-positive quantity via the CHECK constraint', function () {
    $company = Company::factory()->create();

    expect(fn () => DB::table('capacities')->insert([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'capability' => 'Kiln drying',
        'quantity' => 0,
        'unit' => 'm3',
        'period' => 'month',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
