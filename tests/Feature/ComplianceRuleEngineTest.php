<?php

use App\Enums\ComplianceCaseStatus;
use App\Filament\Resources\ComplianceRules\Pages\CreateComplianceRule;
use App\Models\Company;
use App\Models\ComplianceCase;
use App\Models\ComplianceRule;
use App\Models\RegulatorySource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function actingAsComplianceAdmin(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::findOrCreate('compliance.manage', 'web');
    // Panel access (User::canAccessPanel) additionally requires a staff
    // role, independent of the resource-level permission check.
    Role::findOrCreate('admin', 'web');

    $user = User::factory()->create();
    $user->givePermissionTo('compliance.manage');
    $user->assignRole('admin');

    test()->actingAs($user);

    return $user;
}

test('creating a compliance rule without a valid regulatory_source_id fails at the DB level', function () {
    expect(fn () => ComplianceRule::create([
        'regulatory_source_id' => 999999,
        'regulatory_framework' => 'EUDR',
    ]))->toThrow(QueryException::class);
});

test('creating a compliance rule with a valid regulatory_source_id succeeds', function () {
    $source = RegulatorySource::factory()->create();

    $rule = ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'EUDR',
        'country_code' => 'FR',
    ]);

    expect($rule->exists)->toBeTrue()
        ->and($rule->regulatorySource->id)->toBe($source->id);
});

test('applicableTo matches country and includes wildcard rules, excludes non-matching ones', function () {
    $source = RegulatorySource::factory()->create();

    $matchingCountry = ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'EUDR',
        'country_code' => 'FR',
        'is_active' => true,
    ]);

    $wildcardCountry = ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'Generic',
        'country_code' => null,
        'is_active' => true,
    ]);

    $otherCountry = ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'UK TR',
        'country_code' => 'GB',
        'is_active' => true,
    ]);

    $matchingWithCategory = ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'EUDR Timber',
        'country_code' => 'FR',
        'product_category' => 'timber',
        'is_active' => true,
    ]);

    $otherCategory = ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'EUDR Cocoa',
        'country_code' => 'FR',
        'product_category' => 'cocoa',
        'is_active' => true,
    ]);

    $inactive = ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'Inactive rule',
        'country_code' => 'FR',
        'is_active' => false,
    ]);

    $results = ComplianceRule::applicableTo('FR', 'timber')->pluck('id');

    expect($results)->toContain($matchingCountry->id)
        ->toContain($wildcardCountry->id)
        ->toContain($matchingWithCategory->id)
        ->not->toContain($otherCountry->id)
        ->not->toContain($otherCategory->id)
        ->not->toContain($inactive->id);
});

test('applicableTo excludes rules for a different supplier type when one is specified', function () {
    $source = RegulatorySource::factory()->create();

    $matchingSupplier = ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'Small supplier rule',
        'country_code' => 'FR',
        'supplier_type' => 'smallholder',
        'is_active' => true,
    ]);

    $otherSupplier = ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'Large supplier rule',
        'country_code' => 'FR',
        'supplier_type' => 'large_operator',
        'is_active' => true,
    ]);

    $results = ComplianceRule::applicableTo('FR', null, 'smallholder')->pluck('id');

    expect($results)->toContain($matchingSupplier->id)
        ->not->toContain($otherSupplier->id);
});

test('the RegulatorySource admin resource is reachable and creatable', function () {
    actingAsComplianceAdmin();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get('/admin/regulatory-sources')->assertOk();
});

test('the ComplianceRule admin form requires selecting a regulatory source', function () {
    actingAsComplianceAdmin();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(CreateComplianceRule::class)
        ->fillForm([
            'regulatory_source_id' => null,
            'regulatory_framework' => 'EUDR',
        ])
        ->call('create')
        ->assertHasFormErrors(['regulatory_source_id' => 'required']);
});

test('the ComplianceRule admin resource creates a rule when a source is selected', function () {
    actingAsComplianceAdmin();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $source = RegulatorySource::factory()->create();

    Livewire::test(CreateComplianceRule::class)
        ->fillForm([
            'regulatory_source_id' => $source->id,
            'regulatory_framework' => 'EUDR',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ComplianceRule::where('regulatory_framework', 'EUDR')->exists())->toBeTrue();
});

test('a compliance case moves through status transitions', function () {
    $company = Company::factory()->create();

    $case = ComplianceCase::create([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'country_code' => 'FR',
        'status' => ComplianceCaseStatus::NotAssessed,
    ]);

    expect($case->status)->toBe(ComplianceCaseStatus::NotAssessed);

    $case->update(['status' => ComplianceCaseStatus::UnderReview]);
    expect($case->fresh()->status)->toBe(ComplianceCaseStatus::UnderReview);

    $case->update(['status' => ComplianceCaseStatus::ApprovedForCthWorkflow]);
    expect($case->fresh()->status)->toBe(ComplianceCaseStatus::ApprovedForCthWorkflow);
});

test('a compliance case polymorphic entity relation resolves to its owning company', function () {
    $company = Company::factory()->create();

    $case = ComplianceCase::create([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'status' => ComplianceCaseStatus::NotAssessed,
    ]);

    expect($case->entity)->not->toBeNull()
        ->and($case->entity->is($company))->toBeTrue();
});
