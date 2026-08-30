<?php

use App\Enums\OrganisationType;
use App\Filament\Exporter\Pages\OnboardingChecklist;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function onboardingOwnerFor(Company $company): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

it('renders the onboarding checklist page for an authenticated company owner', function () {
    $company = Company::factory()->create();

    $this->actingAs(onboardingOwnerFor($company));

    $this->get('/dashboard/onboarding-checklist')->assertOk();
});

it('shows export-flavoured copy for a supplier-type company', function () {
    $company = Company::factory()->create(['type' => OrganisationType::Supplier]);
    $user = onboardingOwnerFor($company);
    $this->actingAs($user);

    $page = Livewire::test(OnboardingChecklist::class);

    $detailText = collect($page->instance()->getChecklist())->pluck('detail')->implode(' | ');

    expect($detailText)->toContain('export');
});

it('does not show export-specific wording for a logistics-type company', function () {
    $company = Company::factory()->create(['type' => OrganisationType::Logistics]);
    $user = onboardingOwnerFor($company);
    $this->actingAs($user);

    $page = Livewire::test(OnboardingChecklist::class);

    $labels = collect($page->instance()->getChecklist())->pluck('label');
    $detailText = collect($page->instance()->getChecklist())->pluck('detail')->implode(' | ');

    expect($labels)->not->toContain('Species / products added');
    expect($detailText)->not->toContain('export licence');
    expect($detailText)->not->toContain('export permit');
});

it('does not show export-specific wording for an artisan-type company', function () {
    $company = Company::factory()->create(['type' => OrganisationType::Artisan]);
    $user = onboardingOwnerFor($company);
    $this->actingAs($user);

    $page = Livewire::test(OnboardingChecklist::class);

    $detailText = collect($page->instance()->getChecklist())->pluck('detail')->implode(' | ');

    expect($detailText)->not->toContain('export licence');
    expect($detailText)->not->toContain('export permit');
    expect($detailText)->toContain('products use');
});

it('still returns a full, sensible checklist for a legacy company with no type', function () {
    $company = Company::factory()->create(['type' => null]);
    $user = onboardingOwnerFor($company);
    $this->actingAs($user);

    $page = Livewire::test(OnboardingChecklist::class);

    $checklist = collect($page->instance()->getChecklist());

    expect($checklist->count())->toBe(7);
    expect($checklist->pluck('label'))->toContain('Species / products added');
});
