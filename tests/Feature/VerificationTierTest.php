<?php

use App\Enums\VerificationStage;
use App\Enums\VerificationTier;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Models\Company;
use App\Models\User;
use App\Models\Verification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function tierStaff(string $role = 'admin'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('hard-caps verification_tier at 0 for a company that is not isVerified()', function () {
    $company = Company::factory()->create();

    expect($company->isVerified())->toBeFalse();

    $company->verification_tier = VerificationTier::TraceabilityVerified;
    $company->save();

    expect($company->fresh()->verification_tier)->toBe(VerificationTier::Unverified);
});

it('does not let a crafted direct attribute assignment bypass the hard cap', function () {
    $company = Company::factory()->create();

    $company->verification_tier = 5;
    $company->save();

    expect($company->fresh()->verification_tier)->toBe(VerificationTier::Unverified);
});

it('allows tier 1 once the company reaches Verified under the existing workflow', function () {
    $company = Company::factory()->create();
    Verification::factory()->create([
        'entity_type' => Company::class,
        'entity_id' => $company->id,
        'stage' => VerificationStage::Verified,
    ]);
    $company->unsetRelation('verification');

    expect($company->isVerified())->toBeTrue();

    $company->verification_tier = VerificationTier::IdentityVerified;
    $company->save();

    expect($company->fresh()->verification_tier)->toBe(VerificationTier::IdentityVerified);
});

it('lets an admin with verification.review set tiers 2-5 once the company is verified', function () {
    $company = Company::factory()->create();
    Verification::factory()->create([
        'entity_type' => Company::class,
        'entity_id' => $company->id,
        'stage' => VerificationStage::Verified,
    ]);
    $company->unsetRelation('verification');

    $this->actingAs(tierStaff('admin'));

    Livewire::test(EditCompany::class, ['record' => $company->slug])
        ->assertFormFieldExists('verification_tier')
        ->fillForm(['verification_tier' => VerificationTier::TraceabilityVerified->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()->verification_tier)->toBe(VerificationTier::TraceabilityVerified);
});

it('hides the verification tier field from a staff user without verification.review', function () {
    $company = Company::factory()->create();

    // content_manager needs companies.manage to reach the Edit page at all
    // (its seeded permissions only include companies.view), but deliberately
    // does NOT hold verification.review, so the tier Section stays hidden.
    $user = tierStaff('content_manager');
    $user->givePermissionTo('companies.manage');
    $this->actingAs($user);

    Livewire::test(EditCompany::class, ['record' => $company->slug])
        ->assertFormFieldIsHidden('verification_tier');
});

it('shows the tier scope on the public profile and no badge at all for tier 0', function () {
    $verified = Company::factory()->publiclyVisible()->create();
    Verification::factory()->create([
        'entity_type' => Company::class,
        'entity_id' => $verified->id,
        'stage' => VerificationStage::Verified,
    ]);
    $verified->unsetRelation('verification');
    $verified->verification_tier = VerificationTier::IdentityVerified;
    $verified->save();

    $this->get(route('companies.show', $verified->slug))
        ->assertOk()
        ->assertSee('CTH Verified Supplier — Identity Verified')
        ->assertSee(VerificationTier::IdentityVerified->scopeDescription());

    $unverified = Company::factory()->publiclyVisible()->create();
    // publiclyVisible() only sets Company::status, not the tier -- confirm it defaults to 0.
    expect($unverified->verification_tier)->toBe(VerificationTier::Unverified);

    $this->get(route('companies.show', $unverified->slug))
        ->assertOk()
        ->assertDontSee('CTH Verified Supplier');
});
