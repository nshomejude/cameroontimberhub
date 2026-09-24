<?php

use App\Enums\BadgeStatus;
use App\Enums\BadgeType;
use App\Enums\CompanyStatus;
use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\VerificationBadge;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function verificationApiSupplier(array $companyAttributes = []): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create($companyAttributes);
    $company->users()->attach($user, ['role' => 'owner', 'is_primary' => true]);

    return [$user, $company];
}

it('requires supplier auth (a plain buyer 403s)', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/company/verification')
        ->assertForbidden();
});

it('refuses an unauthenticated call', function () {
    $this->getJson('/api/v1/company/verification')->assertUnauthorized();
});

it('returns the real company status and active badges', function () {
    [$user, $company] = verificationApiSupplier(['status' => CompanyStatus::Verified]);

    VerificationBadge::factory()->create([
        'company_id' => $company->getKey(),
        'badge_type' => BadgeType::VerifiedExporter,
        'status' => BadgeStatus::Active,
    ]);

    // A revoked badge must not appear.
    VerificationBadge::factory()->revoked()->create([
        'company_id' => $company->getKey(),
        'badge_type' => BadgeType::SigifRegistered,
    ]);

    // An expired badge must not appear either.
    VerificationBadge::factory()->expired()->create([
        'company_id' => $company->getKey(),
        'badge_type' => BadgeType::ExportReady,
    ]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/company/verification')
        ->assertOk()
        ->assertJsonPath('data.status', 'verified');

    expect($response->json('data.badges'))->toHaveCount(1)
        ->and($response->json('data.badges.0.type'))->toBe('verified_exporter');
});

it('lists required document types the company has not yet had approved as missing_documents', function () {
    [$user, $company] = verificationApiSupplier(['status' => CompanyStatus::Draft]);

    $required = DocumentType::factory()->create(['key' => 'business_registration', 'name' => 'Business Registration', 'is_required' => true]);
    DocumentType::factory()->create(['key' => 'optional_doc', 'name' => 'Optional Doc', 'is_required' => false]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/company/verification')
        ->assertOk();

    expect(collect($response->json('data.missing_documents'))->pluck('key')->all())
        ->toContain('business_registration')
        ->and($response->json('data.next_step'))->toContain('Business Registration');

    // Once approved, it drops off the missing list.
    CompanyDocument::factory()->create([
        'company_id' => $company->getKey(),
        'document_type_id' => $required->getKey(),
        'status' => DocumentStatus::Approved,
    ]);

    $response2 = $this->actingAs($user, 'sanctum')->getJson('/api/v1/company/verification')->assertOk();

    expect(collect($response2->json('data.missing_documents'))->pluck('key')->all())
        ->not->toContain('business_registration');
});

it('gives a clean next_step for an already-verified company with no missing documents', function () {
    [$user] = verificationApiSupplier(['status' => CompanyStatus::Verified]);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/company/verification')
        ->assertOk()
        ->assertJsonPath('data.missing_documents', [])
        ->assertJsonPath('data.next_step', 'No action needed — your company is verified.');
});

it('rejects submission with the real missing requirements when the profile is incomplete', function () {
    [$user] = verificationApiSupplier([
        'status' => CompanyStatus::Draft,
        'description' => null,
        'region' => null,
        'logo_path' => null,
    ]);

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/company/verification/submit')
        ->assertStatus(422);

    expect($response->json('error.code'))->toBe('profile_incomplete')
        ->and($response->json('error.details.missing'))->toContain('A logo');
});

it('submits for verification once the profile is complete, mirroring EditCompany::submitForReview', function () {
    [$user, $company] = verificationApiSupplier([
        'status' => CompanyStatus::Draft,
        'legal_name' => 'Test Timber Sarl',
        'description' => str_repeat('A real company description. ', 3),
        'region' => 'Littoral',
        'logo_path' => 'companies/demo/logo.png',
    ]);

    $company->contacts()->create(['name' => 'Sales Desk', 'is_public' => true]);
    $species = \App\Models\Species::factory()->create();
    $company->species()->attach($species->getKey());

    $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/company/verification/submit')
        ->assertCreated();

    expect($response->json('data.status'))->toBe('pending');
    expect($company->verificationRequests()->count())->toBe(1);
});

it('does not duplicate an already-open verification request on a second submit', function () {
    [$user, $company] = verificationApiSupplier([
        'status' => CompanyStatus::Draft,
        'legal_name' => 'Test Timber Sarl',
        'description' => str_repeat('A real company description. ', 3),
        'region' => 'Littoral',
        'logo_path' => 'companies/demo/logo.png',
    ]);

    $company->contacts()->create(['name' => 'Sales Desk', 'is_public' => true]);
    $species = \App\Models\Species::factory()->create();
    $company->species()->attach($species->getKey());

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/company/verification/submit')->assertCreated();
    $this->actingAs($user, 'sanctum')->postJson('/api/v1/company/verification/submit')->assertCreated();

    expect($company->verificationRequests()->count())->toBe(1);
});
