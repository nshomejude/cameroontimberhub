<?php

use App\Enums\ComplianceCaseStatus;
use App\Filament\Pages\ComplianceOfficerWorkspace;
use App\Models\Company;
use App\Models\ComplianceCase;
use App\Models\ComplianceRule;
use App\Models\RegulatorySource;
use App\Models\RiskAssessment;
use App\Models\User;
use App\Models\VerificationRevocationRequest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function complianceOfficer(): User
{
    $user = User::factory()->create(['email' => 'officer'.uniqid().'@example.com']);
    $user->assignRole('admin');

    return $user;
}

/* --------------------------------------------------------------- access */

it('blocks a user without compliance.manage from the workspace', function () {
    $plain = User::factory()->create();

    $this->actingAs($plain)->get(ComplianceOfficerWorkspace::getUrl())->assertForbidden();
});

it('lets a compliance officer reach the workspace', function () {
    $officer = complianceOfficer();

    $this->actingAs($officer)->get(ComplianceOfficerWorkspace::getUrl())->assertOk();
});

/* -------------------------------------------------------------- content */

it('shows open cases by status, high-risk companies, rule updates and pending revocations', function () {
    $officer = complianceOfficer();

    $flaggedCompany = Company::factory()->create(['trade_name' => 'Flagged Timber Co']);
    ComplianceCase::create([
        'owner_type' => Company::class,
        'owner_id' => $flaggedCompany->id,
        'status' => ComplianceCaseStatus::HighRisk,
        'opened_at' => now(),
    ]);

    RiskAssessment::computeFor($flaggedCompany)->forceFill(['risk_band' => 'critical', 'composite_score' => 90])->save();

    $source = RegulatorySource::create([
        'authority' => 'EU Commission',
        'instrument_name' => 'EUDR',
        'jurisdiction' => 'EU',
    ]);
    ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'EUDR Due Diligence',
        'is_active' => true,
    ]);

    $revocationCompany = Company::factory()->create(['trade_name' => 'Revocation Target Co']);
    VerificationRevocationRequest::create([
        'company_id' => $revocationCompany->id,
        'requested_by' => $officer->id,
        'reason' => 'Fraudulent documents',
        'status' => 'pending',
    ]);

    $response = $this->actingAs($officer)->get(ComplianceOfficerWorkspace::getUrl());

    $response->assertOk()
        ->assertSee('High Risk')
        ->assertSee('Flagged Timber Co')
        ->assertSee('EUDR Due Diligence')
        ->assertSee('Revocation Target Co');
});

it('shows a graceful empty state when nothing needs the officers attention', function () {
    $officer = complianceOfficer();

    $this->actingAs($officer)->get(ComplianceOfficerWorkspace::getUrl())
        ->assertOk()
        ->assertSee('No open compliance cases right now.')
        ->assertSee('No company currently sits in the high or critical risk band.')
        ->assertSee('No verification revocation requests are awaiting approval.');
});
