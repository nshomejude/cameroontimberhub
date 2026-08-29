<?php

use App\Enums\BadgeStatus;
use App\Enums\CompanyStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentVisibility;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\DocumentAccessLog;
use App\Models\DocumentReminderLog;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\VerificationService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
});

function docFor(Company $company, string $typeKey, array $attributes = []): CompanyDocument
{
    $type = DocumentType::where('key', $typeKey)->firstOrFail();

    return $company->documents()->create(array_merge([
        'document_type_id' => $type->id,
        'original_filename' => $typeKey.'.pdf',
        'storage_path' => 'x/'.$typeKey.'.pdf',
        'disk' => 'documents',
        'mime_type' => 'application/pdf',
        'file_size' => 100,
        'status' => DocumentStatus::Pending,
        'visibility' => DocumentVisibility::Private,
    ], $attributes));
}

it('runs the verification flow and issues only backed badges', function () {
    $company = Company::factory()->create([
        'status' => CompanyStatus::Draft,
        'logo_path' => 'l.png',
        'region' => 'Centre',
        'description' => str_repeat('timber ', 12),
    ]);
    $doc = docFor($company, 'business_registration');
    $admin = User::factory()->create();
    $vs = app(VerificationService::class);

    $request = $vs->submit($company, ['verified_company', 'verified_exporter']);
    expect($company->fresh()->status)->toBe(CompanyStatus::Pending);

    $vs->startReview($request->fresh(), $admin);
    $vs->approveDocument($doc->fresh(), $admin);
    $result = $vs->approve($request->fresh(), $admin);

    expect($result['issued'])->toContain('verified_company')
        ->and($result['skipped'])->toContain('verified_exporter') // missing export_permit
        ->and($company->fresh()->status)->toBe(CompanyStatus::Verified)
        ->and($company->activeBadges()->count())->toBe(1);
});

it('expires badges past their valid_until and drops the company from public listings', function () {
    $company = Company::factory()->publiclyVisible()->create();
    expect(Company::publiclyVisible()->whereKey($company->id)->exists())->toBeTrue();

    $company->verificationBadges()->update(['valid_until' => today()->subDay()]);

    $this->artisan('compliance:expire-badges')->assertSuccessful();

    expect($company->verificationBadges()->where('status', BadgeStatus::Active->value)->count())->toBe(0)
        ->and(Company::publiclyVisible()->whereKey($company->id)->exists())->toBeFalse();
});

it('sends document-expiry reminders once per threshold (idempotent)', function () {
    Notification::fake();
    $company = Company::factory()->create();
    $doc = docFor($company, 'export_permit', [
        'status' => DocumentStatus::Approved,
        'expiry_date' => today()->addDays(20), // 30-day bucket
    ]);

    $this->artisan('compliance:remind-expiring')->assertSuccessful();
    $this->artisan('compliance:remind-expiring')->assertSuccessful(); // re-run is a no-op

    expect(DocumentReminderLog::where('document_owner_type', CompanyDocument::class)->where('document_owner_id', $doc->id)->where('threshold', '30')->count())->toBe(1);
});

it('rejects unsigned document downloads and logs signed-URL issuance', function () {
    $company = Company::factory()->create();
    $doc = docFor($company, 'business_registration');
    $owner = User::factory()->create();
    $owner->companies()->attach($company, ['role' => 'owner']);

    $this->actingAs($owner)->get(route('documents.download', $doc))->assertForbidden();

    app(DocumentService::class)->signedDownloadUrl($doc, $owner);

    expect(DocumentAccessLog::where('company_document_id', $doc->id)->where('action', 'signed_url_issued')->count())->toBe(1);
});

it('gates the compliance panels by permission', function () {
    $officer = User::factory()->create();
    $officer->assignRole('verification_officer');
    $content = User::factory()->create();
    $content->assignRole('content_manager');

    $this->actingAs($officer)->get('/admin/verification-requests')->assertOk();
    $this->actingAs($officer)->get('/admin/company-documents')->assertOk();
    $this->actingAs($content)->get('/admin/verification-requests')->assertForbidden();
});

it('lets a company member open the document upload form', function () {
    $company = Company::factory()->create();
    $member = User::factory()->create();
    $member->companies()->attach($company, ['role' => 'owner']);

    $this->actingAs($member)->get('/dashboard/company-documents')->assertOk();
    $this->actingAs($member)->get('/dashboard/company-documents/create')->assertOk();
});
