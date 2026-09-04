<?php

use App\Enums\LotEventType;
use App\Models\Company;
use App\Models\ComplianceRule;
use App\Models\Inspection;
use App\Models\Inspector;
use App\Models\LotTransformation;
use App\Models\RegulatorySource;
use App\Models\TimberLot;
use App\Models\User;
use App\Services\ComplianceEvidencePackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;

uses(RefreshDatabase::class);

function companyOwnerUser(Company $company): User
{
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => 'owner']);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Access control
|--------------------------------------------------------------------------
*/

test('a company can download the compliance pack for its own timber lot', function () {
    $company = Company::factory()->create();
    $user = companyOwnerUser($company);
    $lot = TimberLot::factory()->for($company)->create();

    $response = $this->actingAs($user)->get(route('compliance-pack.download', $lot));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    // A real generated PDF, not an error page rendered with a 200 status.
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

test('a different company\'s user cannot download the pack, and gets no file', function () {
    $owningCompany = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $otherUser = companyOwnerUser($otherCompany);
    $lot = TimberLot::factory()->for($owningCompany)->create();

    $response = $this->actingAs($otherUser)->get(route('compliance-pack.download', $lot));

    $response->assertForbidden();
    expect($response->headers->get('content-type'))->not->toContain('application/pdf');
});

test('a guest cannot download the pack', function () {
    $company = Company::factory()->create();
    $lot = TimberLot::factory()->for($company)->create();

    $response = $this->get(route('compliance-pack.download', $lot));

    $response->assertRedirect(route('login'));
});

test('staff with compliance.export permission can download any lot\'s pack', function () {
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    \Spatie\Permission\Models\Permission::findOrCreate('compliance.export', 'web');

    $company = Company::factory()->create();
    $lot = TimberLot::factory()->for($company)->create();

    $staff = User::factory()->create();
    $staff->givePermissionTo('compliance.export');

    $response = $this->actingAs($staff)->get(route('compliance-pack.download', $lot));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('downloading a pack for a lot with minimal data does not error', function () {
    $company = Company::factory()->create();
    $user = companyOwnerUser($company);
    $lot = TimberLot::factory()->for($company)->create([
        'origin_region' => null,
        'origin_latitude' => null,
        'origin_longitude' => null,
    ]);

    $response = $this->actingAs($user)->get(route('compliance-pack.download', $lot));

    $response->assertOk();
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

/*
|--------------------------------------------------------------------------
| Content correctness
|--------------------------------------------------------------------------
|
| A generated PDF's text is glyph/font-encoded binary, not searchable ASCII
| — asserting directly on Pdf::download()'s raw bytes is not a meaningful
| test of *content*. Instead these assert on the same two things dompdf
| actually turns into the PDF: the service's assembled data, and the exact
| HTML the Blade view renders from it (the layer where the disclosure
| restraint and empty-state fallbacks actually live).
|
*/

test('the service assembles lot number, species, applicable compliance rules, events, and inspection summary', function () {
    $company = Company::factory()->create([
        'legal_name' => 'Test Timber Exporters Ltd',
        'registration_number' => 'RC-12345',
    ]);
    $lot = TimberLot::factory()->for($company)->create([
        'origin_region' => 'East Region',
        'origin_latitude' => 3.848000,
        'origin_longitude' => 11.502000,
    ]);

    $source = RegulatorySource::factory()->create();
    $rule = ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'EUDR',
        'country_code' => 'CM',
    ]);

    $event = $lot->recordEvent(LotEventType::SourceRegistered, ['location' => 'Yaoundé']);

    $inspector = Inspector::create([
        'user_id' => User::factory()->create()->id,
        'organisation_name' => 'Test Inspection Co',
        'identity_verified_at' => now(),
        'agreement_accepted_at' => now(),
        'status' => 'active',
    ]);
    $inspection = Inspection::create([
        'inspector_id' => $inspector->id,
        'timber_lot_id' => $lot->id,
        'inspection_type' => 'quality_grade',
        'result' => 'pass',
        'quality_findings' => 'Good grade throughout.',
    ]);
    $inspection->finalise();
    $inspection->refresh();

    $data = app(ComplianceEvidencePackService::class)->buildFor($lot, 'CM');

    expect($data['lot']->lot_number)->toBe($lot->lot_number)
        ->and($data['lot']->species->common_name)->toBe($lot->species->common_name)
        ->and($data['supplier']['legal_name'])->toBe('Test Timber Exporters Ltd')
        ->and($data['supplier']['registration_number'])->toBe('RC-12345')
        ->and($data['origin']['region'])->toBe('East Region')
        // Origin is region-based; the raw precise coordinates are never surfaced.
        ->and($data['origin']['approximate_location'])->toBeNull()
        ->and($data['compliance_rules']->pluck('id'))->toContain($rule->id)
        ->and($data['lot_events']->pluck('id'))->toContain($event->id)
        ->and($data['inspection']['result'])->toBe('pass')
        ->and($data['inspection']['digital_signature'])->toBe($inspection->digital_signature);
});

test('the rendered view includes lot number, species, compliance rules, events, and inspection summary, and never raw GPS or another company\'s data', function () {
    $company = Company::factory()->create([
        'legal_name' => 'Test Timber Exporters Ltd',
        'registration_number' => 'RC-12345',
    ]);
    $otherCompany = Company::factory()->create(['legal_name' => 'Rival Exporters Inc']);

    $lot = TimberLot::factory()->for($company)->create([
        'origin_region' => 'East Region',
        'origin_latitude' => 3.848000,
        'origin_longitude' => 11.502000,
    ]);

    $source = RegulatorySource::factory()->create();
    ComplianceRule::create([
        'regulatory_source_id' => $source->id,
        'regulatory_framework' => 'EUDR',
        'country_code' => 'CM',
    ]);

    $lot->recordEvent(LotEventType::SourceRegistered, ['location' => 'Yaoundé']);

    $inspector = Inspector::create([
        'user_id' => User::factory()->create()->id,
        'organisation_name' => 'Test Inspection Co',
        'identity_verified_at' => now(),
        'agreement_accepted_at' => now(),
        'status' => 'active',
    ]);
    $inspection = Inspection::create([
        'inspector_id' => $inspector->id,
        'timber_lot_id' => $lot->id,
        'inspection_type' => 'quality_grade',
        'result' => 'pass',
        'quality_findings' => 'Good grade throughout.',
    ]);
    $inspection->finalise();

    $data = app(ComplianceEvidencePackService::class)->buildFor($lot, 'CM');
    $html = View::make('pdf.compliance-pack', $data)->render();

    expect($html)->toContain($lot->lot_number)
        ->toContain($lot->species->common_name)
        ->toContain('EUDR')
        ->toContain('Source Registered')
        ->toContain('quality_grade')
        ->toContain('Test Timber Exporters Ltd')
        // Never the raw precise GPS coordinates, even though the model has them.
        ->not->toContain('3.848000')
        ->not->toContain('11.502000')
        // Never another company's private data.
        ->not->toContain('Rival Exporters Inc');
});

test('the rendered view shows empty-data placeholders instead of crashing for a lot with no events, inspection, or transformations', function () {
    $company = Company::factory()->create();
    $lot = TimberLot::factory()->for($company)->create([
        'origin_region' => null,
        'origin_latitude' => null,
        'origin_longitude' => null,
    ]);

    $data = app(ComplianceEvidencePackService::class)->buildFor($lot);
    $html = View::make('pdf.compliance-pack', $data)->render();

    expect($html)->toContain($lot->lot_number)
        ->toContain('No traceability events available')
        ->toContain('No processing records available')
        ->toContain('No finalised inspection report available');
});

test('the rendered view includes processing history for a lot with a transformation record', function () {
    $company = Company::factory()->create();
    $inputLot = TimberLot::factory()->for($company)->create();
    $outputLot = TimberLot::factory()->for($company)->create();

    LotTransformation::recordFor(
        $company->id,
        'sawing',
        [['lot' => $inputLot, 'quantity' => 10]],
        [['lot' => $outputLot, 'quantity' => 8]],
    );

    $data = app(ComplianceEvidencePackService::class)->buildFor($outputLot);
    $html = View::make('pdf.compliance-pack', $data)->render();

    expect($html)->toContain('Sawing');
});
