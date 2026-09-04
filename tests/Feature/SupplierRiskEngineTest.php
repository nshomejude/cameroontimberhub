<?php

use App\Enums\CompanyStatus;
use App\Enums\ComplianceCaseStatus;
use App\Enums\DocumentStatus;
use App\Enums\VerificationStage;
use App\Enums\VerificationTier;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\ComplianceCase;
use App\Models\RiskAssessment;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function verifyCompany(Company $company, VerificationTier $tier = VerificationTier::IdentityVerified): Company
{
    Verification::factory()->create([
        'entity_type' => Company::class,
        'entity_id' => $company->id,
        'stage' => VerificationStage::Verified,
    ]);

    $company->unsetRelation('verification');
    $company->verification_tier = $tier;
    $company->save();

    return $company->fresh();
}

test('a fully-verified, well-documented, dispute-free company lands low', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified]);
    $company = verifyCompany($company, VerificationTier::FieldVerified);

    CompanyDocument::factory()->approved()->count(3)->create(['company_id' => $company->id]);

    $assessment = RiskAssessment::computeFor($company->fresh());

    expect($assessment->composite_score)->toBeLessThan(60)
        ->and($assessment->risk_band)->toBeIn(['low_concern', 'moderate', 'elevated']);
});

test('an unverified company with no documents lands higher than a verified one', function () {
    $unverified = Company::factory()->create(['status' => CompanyStatus::Draft]);
    $verified = Company::factory()->create(['status' => CompanyStatus::Verified]);
    $verified = verifyCompany($verified, VerificationTier::FieldVerified);
    CompanyDocument::factory()->approved()->count(3)->create(['company_id' => $verified->id]);

    $unverifiedAssessment = RiskAssessment::computeFor($unverified->fresh());
    $verifiedAssessment = RiskAssessment::computeFor($verified->fresh());

    expect($unverifiedAssessment->composite_score)->toBeGreaterThan($verifiedAssessment->composite_score);
});

test('dispute_risk defaults to a documented neutral value since no dispute model exists', function () {
    $company = Company::factory()->create();

    $assessment = RiskAssessment::computeFor($company);

    expect($assessment->dispute_risk)->toBe(50);
});

test('compliance_risk rises with open high-risk-adjacent compliance cases', function () {
    $company = Company::factory()->create();

    $clean = RiskAssessment::computeFor($company);

    ComplianceCase::create([
        'owner_type' => Company::class,
        'owner_id' => $company->id,
        'status' => ComplianceCaseStatus::HighRisk,
        'opened_at' => now(),
    ]);

    $withCase = RiskAssessment::computeFor($company->fresh());

    expect($withCase->compliance_risk)->toBeGreaterThan($clean->compliance_risk);
});

test('documentation_risk rises when documents are missing or expired/pending', function () {
    $noDocs = Company::factory()->create();
    $withDocs = Company::factory()->create();
    CompanyDocument::factory()->approved()->count(4)->create(['company_id' => $withDocs->id]);

    $noDocsAssessment = RiskAssessment::computeFor($noDocs);
    $withDocsAssessment = RiskAssessment::computeFor($withDocs->fresh());

    expect($noDocsAssessment->documentation_risk)->toBeGreaterThan($withDocsAssessment->documentation_risk);
});

test('composite score / risk band mapping is correct at each of the 5 band boundaries', function (int $score, string $expectedBand) {
    expect(RiskAssessment::bandFor($score))->toBe($expectedBand);
})->with([
    [0, 'low_concern'],
    [19, 'low_concern'],
    [20, 'moderate'],
    [39, 'moderate'],
    [40, 'elevated'],
    [59, 'elevated'],
    [60, 'high'],
    [79, 'high'],
    [80, 'critical'],
    [100, 'critical'],
]);

test('the console command creates rows for multiple companies without erroring', function () {
    Company::factory()->count(3)->create();

    $this->artisan('risk:compute-all')->assertSuccessful();

    expect(RiskAssessment::count())->toBe(3);
});

test('running the command twice appends rather than overwrites', function () {
    Company::factory()->create();

    $this->artisan('risk:compute-all')->assertSuccessful();
    $this->artisan('risk:compute-all')->assertSuccessful();

    expect(RiskAssessment::count())->toBe(2);
});

test('public company profile view does not expose raw risk scores', function () {
    $company = Company::factory()->publiclyVisible()->create();
    RiskAssessment::computeFor($company)->save();

    $response = $this->get(route('companies.show', $company));

    $response->assertOk();
    $response->assertDontSee('composite_score', false);
    $response->assertDontSee('risk_band', false);
    $response->assertDontSee('identity_risk', false);
});
