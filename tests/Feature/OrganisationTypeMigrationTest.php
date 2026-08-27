<?php

use App\Enums\OrganisationType;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has a nullable type column on companies alongside the untouched supplier_type column', function () {
    expect(Schema::hasColumn('companies', 'type'))->toBeTrue()
        ->and(Schema::hasColumn('companies', 'supplier_type'))->toBeTrue();
});

it('allows a null type', function () {
    $company = Company::factory()->create(['type' => null]);

    expect($company->fresh()->type)->toBeNull();
});

it('rejects a type value outside the 10-case OrganisationType list', function () {
    expect(fn () => DB::table('companies')->insert([
        ...Company::factory()->raw(),
        'type' => 'not_a_real_type',
    ]))->toThrow(QueryException::class);
});

it('accepts every OrganisationType value', function () {
    foreach (OrganisationType::cases() as $case) {
        $company = Company::factory()->create(['type' => $case->value]);
        expect($company->fresh()->type)->toBe($case);
    }
});
