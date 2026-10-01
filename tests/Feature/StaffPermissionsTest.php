<?php

use App\Enums\CompanyStatus;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\TimberLots\TimberLotResource;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Staff permission matrix for launch: agent submission moderation,
 * moderator/billing/finance grants, TimberLot admin gate, and the
 * "Agent submissions" tab on admin Companies.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function staffUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function agentCompany(bool $needsReview = true): Company
{
    $company = Company::factory()->create();
    $company->forceFill(['source' => 'agent:hermes', 'needs_review' => $needsReview])->save();

    return $company->fresh();
}

it('grants the launch staff permissions', function () {
    expect(staffUser('moderator')->can('products.manage'))->toBeTrue()
        ->and(staffUser('moderator')->can('inquiries.review'))->toBeTrue()
        ->and(staffUser('moderator')->can('companies.view'))->toBeTrue()
        ->and(staffUser('moderator')->can('companies.manage'))->toBeFalse()
        ->and(staffUser('billing_officer')->can('billing.view'))->toBeTrue()
        ->and(staffUser('billing_officer')->can('support.manage'))->toBeTrue()
        ->and(staffUser('finance_officer')->can('payments.view'))->toBeTrue()
        ->and(staffUser('finance_officer')->can('support.manage'))->toBeTrue();

    foreach (['admin', 'verification_officer', 'moderator', 'super_admin'] as $role) {
        expect(staffUser($role)->can('agent-submissions.moderate'))->toBeTrue();
    }
});

it('lets a moderator and a verification officer moderate agent submissions without companies.manage', function (string $role) {
    $company = agentCompany();

    $this->actingAs(staffUser($role));

    Livewire::test(ListCompanies::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$company])
        // Company status authority (companies.manage) is NOT implied.
        ->assertTableActionHidden('archive', $company)
        ->assertTableActionVisible('agentApprove', $company)
        ->callTableAction('agentApprove', $company);

    expect($company->fresh()->needs_review)->toBeFalse()
        ->and($company->fresh()->status)->not->toBe(CompanyStatus::Archived);
})->with(['moderator', 'verification_officer']);

it('hides agent moderation actions from staff without the permission', function () {
    $company = agentCompany();

    // content_manager can view companies but holds neither permission.
    $this->actingAs(staffUser('content_manager'));

    Livewire::test(ListCompanies::class)
        ->assertTableActionHidden('agentApprove', $company)
        ->assertTableActionHidden('agentReject', $company);
});

it('shows an Agent submissions tab scoped to companies needing review', function () {
    $pending = agentCompany(true);
    $cleared = agentCompany(false);
    $regular = Company::factory()->create();

    $this->actingAs(staffUser('admin'));

    $page = Livewire::test(ListCompanies::class);
    expect($page->instance()->getTabs()['agent_submissions']->getBadge())->toEqual(1);

    $page->set('activeTab', 'agent_submissions')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$cleared, $regular]);
});

it('gates the admin TimberLot resource on products.manage', function () {
    $this->actingAs(staffUser('support_officer'));
    expect(TimberLotResource::canViewAny())->toBeFalse();
    $this->get(TimberLotResource::getUrl('index'))->assertForbidden();

    $this->actingAs(staffUser('admin'));
    expect(TimberLotResource::canViewAny())->toBeTrue();
    $this->get(TimberLotResource::getUrl('index'))->assertOk();
});
