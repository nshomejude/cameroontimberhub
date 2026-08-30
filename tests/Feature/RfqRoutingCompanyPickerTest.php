<?php

use App\Enums\OrganisationType;
use App\Models\Company;

/**
 * The RFQ "route" table action (app/Filament/Resources/Rfqs/Tables/RfqsTable.php)
 * populates its company picker with:
 *
 *   Company::where('status', 'verified')->orderBy('legal_name')->get()
 *
 * This asserts that query is not artificially restricted to a subset of
 * company-forming OrganisationType values — every verified company, of every
 * type, must be selectable when staff route an RFQ.
 */
it('includes verified companies of every organisation type in the routing picker query', function () {
    $types = [
        OrganisationType::Supplier,
        OrganisationType::Processor,
        OrganisationType::Manufacturer,
        OrganisationType::Artisan,
        OrganisationType::Logistics,
        OrganisationType::CarbonDeveloper,
    ];

    $companies = collect($types)->map(
        fn (OrganisationType $type) => Company::factory()->verified()->create(['type' => $type])
    );

    // Mirrors the exact query used by RfqsTable's "route" action options() closure.
    $pickerOptionIds = Company::where('status', 'verified')->orderBy('legal_name')->pluck('id')->all();

    foreach ($companies as $company) {
        expect($pickerOptionIds)->toContain($company->id);
    }
});

it('excludes unverified companies from the routing picker query, regardless of type', function () {
    $draft = Company::factory()->create(['type' => OrganisationType::Manufacturer]);

    $pickerOptionIds = Company::where('status', 'verified')->orderBy('legal_name')->pluck('id')->all();

    expect($pickerOptionIds)->not->toContain($draft->id);
});
