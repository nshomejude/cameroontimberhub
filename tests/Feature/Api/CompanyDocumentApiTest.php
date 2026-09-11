<?php

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('documents');
});

function supplierWithCompany(): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user);

    return [$user, $company];
}

/* -------------------------------------------------------------- listing */

it('lists the caller own company documents', function () {
    [$user, $company] = supplierWithCompany();
    $type = DocumentType::factory()->create(['key' => 'business_registration']);

    $document = CompanyDocument::factory()->create([
        'company_id' => $company->getKey(),
        'document_type_id' => $type->getKey(),
        'status' => DocumentStatus::Approved,
    ]);

    // Someone else's document must never leak in.
    CompanyDocument::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/company/documents')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe($document->id)
        ->and($response->json('data.0.type'))->toBe('business_registration')
        ->and($response->json('data.0.status'))->toBe('approved')
        ->and($response->json('data.0.download_url'))->toContain('/api/v1/company/documents/'.$document->id.'/download');
});

it('returns an empty array for a company with no documents', function () {
    [$user] = supplierWithCompany();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/company/documents')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('403s a buyer (not a company member) hitting company documents', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/company/documents')
        ->assertForbidden();
});

it('401s a guest on every company document endpoint', function () {
    $this->getJson('/api/v1/company/documents')->assertUnauthorized();
    $this->postJson('/api/v1/company/documents', [])->assertUnauthorized();
    $this->getJson('/api/v1/company/documents/1/download')->assertUnauthorized();
});

/* -------------------------------------------------------------- upload */

it('uploads a valid document and it appears in a subsequent list', function () {
    [$user] = supplierWithCompany();
    DocumentType::factory()->create(['key' => 'export_permit', 'is_active' => true]);

    $file = UploadedFile::fake()->create('export-permit.pdf', 500, 'application/pdf');

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/company/documents', ['type' => 'export_permit', 'file' => $file])
        ->assertCreated();

    $documentId = $response->json('data.id');
    expect($documentId)->not->toBeNull();

    $this->assertDatabaseHas('company_documents', ['id' => $documentId, 'original_filename' => 'export-permit.pdf']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/company/documents')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $documentId);
});

it('422s an upload with an unknown document type', function () {
    [$user] = supplierWithCompany();
    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/company/documents', ['type' => 'not-a-real-type', 'file' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type', 'error.details');
});

it('422s an upload with a disallowed mime type', function () {
    [$user] = supplierWithCompany();
    DocumentType::factory()->create(['key' => 'other', 'is_active' => true]);
    $file = UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/company/documents', ['type' => 'other', 'file' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file', 'error.details');
});

it('422s an oversized upload', function () {
    [$user] = supplierWithCompany();
    DocumentType::factory()->create(['key' => 'other', 'is_active' => true]);
    $file = UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'); // just over the 10 MB cap

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/company/documents', ['type' => 'other', 'file' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file', 'error.details');
});

it('403s a buyer attempting to upload a company document', function () {
    $buyer = User::factory()->create();
    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/company/documents', ['type' => 'other', 'file' => $file])
        ->assertForbidden();
});

/* ------------------------------------------------------------ download */

it('lets the owning company download its document', function () {
    [$user, $company] = supplierWithCompany();
    $type = DocumentType::factory()->create();

    $document = CompanyDocument::factory()->create([
        'company_id' => $company->getKey(),
        'document_type_id' => $type->getKey(),
        'storage_path' => 'companies/'.$company->getKey().'/documents/sample.pdf',
        'disk' => 'documents',
        'original_filename' => 'sample.pdf',
    ]);

    Storage::disk('documents')->put($document->storage_path, 'fake-bytes');

    $this->actingAs($user, 'sanctum')
        ->get('/api/v1/company/documents/'.$document->id.'/download')
        ->assertOk();
});

it('404s another company member downloading a document that is not theirs', function () {
    [, $company] = supplierWithCompany();
    $type = DocumentType::factory()->create();

    $document = CompanyDocument::factory()->create([
        'company_id' => $company->getKey(),
        'document_type_id' => $type->getKey(),
    ]);

    [$otherUser] = supplierWithCompany();

    $this->actingAs($otherUser, 'sanctum')
        ->getJson('/api/v1/company/documents/'.$document->id.'/download')
        ->assertNotFound();
});
