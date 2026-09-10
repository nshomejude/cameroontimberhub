<?php

use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/*
 * Feature gates for the dormant account-capability roles (gap-plan item 0.7b /
 * production-readiness Batch E, Task E2).
 *
 * Only two of the five dormant roles currently have a real surface to protect:
 *   - logistics_partner -> exporter Vehicle/Driver fleet resources
 *   - carbon_developer  -> exporter CarbonProject resource (complementary to the
 *     existing OrganisationType::CarbonDeveloper gate)
 * processor / artisan / carbon_buyer have only public read-only directories
 * (or nothing) today — see the RolesAndPermissionsSeeder comments.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
});

function accountRoleMember(Company $company, ?string $role = null): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
}

// --- logistics_partner -------------------------------------------------------

it('lets a logistics_partner reach the fleet resources', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $user = accountRoleMember($company, 'logistics_partner');

    $this->actingAs($user)->get('/dashboard/vehicles')->assertOk();
    $this->actingAs($user)->get('/dashboard/drivers')->assertOk();
});

it('lets a member of an OrganisationType::Logistics company reach the fleet resources', function () {
    $company = Company::factory()->publiclyVisible()->create(['type' => OrganisationType::Logistics]);
    $user = accountRoleMember($company);

    $this->actingAs($user)->get('/dashboard/vehicles')->assertOk();
});

it('forbids a non-logistics company member without the logistics_partner role from the fleet resources', function () {
    $company = Company::factory()->publiclyVisible()->create(['type' => OrganisationType::Supplier]);
    $user = accountRoleMember($company);

    $this->actingAs($user)->get('/dashboard/vehicles')->assertForbidden();
    $this->actingAs($user)->get('/dashboard/drivers')->assertForbidden();
});

// --- carbon_developer ------------------------------------------------------

it('lets a carbon_developer role holder reach the carbon projects resource', function () {
    $company = Company::factory()->publiclyVisible()->create(['type' => OrganisationType::Supplier]);
    $user = accountRoleMember($company, 'carbon_developer');

    $this->actingAs($user)->get('/dashboard/carbon-projects')->assertOk();
});

it('forbids a plain company member without carbon_developer role or company type from the carbon projects resource', function () {
    $company = Company::factory()->publiclyVisible()->create(['type' => OrganisationType::Supplier]);
    $user = accountRoleMember($company);

    $this->actingAs($user)->get('/dashboard/carbon-projects')->assertForbidden();
});
