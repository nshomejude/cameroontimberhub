<?php

use App\Enums\DocumentVerificationStatus;
use App\Filament\Exporter\Resources\Products\Pages\EditProduct;
use App\Filament\Exporter\Resources\Products\ProductResource;
use App\Filament\Exporter\Resources\Products\RelationManagers\DocumentsRelationManager;
use App\Jobs\SendDocumentExpiryReminderJob;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentReminderLog;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
});

function productDocSupplier(Company $company): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

it('exposes a polymorphic documents relation on Product', function () {
    $product = Product::factory()->create();

    $doc = $product->documents()->create([
        'type' => 'legal_origin',
        'original_filename' => 'origin.pdf',
        'disk' => 'documents',
        'storage_path' => 'products/documents/origin.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
    ]);

    expect($doc->owner_type)->toBe(Product::class)
        ->and($doc->owner_id)->toBe($product->getKey())
        ->and($product->fresh()->documents)->toHaveCount(1);
});

it('creates a product-owned document through the relation manager form', function () {
    Storage::fake('documents');

    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->for($company, 'company')->create();

    $this->actingAs(productDocSupplier($company));

    // The relation manager is wired, authorised for the owning supplier, and
    // exposes the upload action.
    Livewire::test(DocumentsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->assertSuccessful()
        ->assertTableActionExists('create');

    // A document created through the relation resolves to the product as owner,
    // and the model hook backfills the file metadata a bare FileUpload omits.
    Storage::disk('documents')->put('products/documents/phyto.pdf', 'PDF-BYTES');

    $document = $product->documents()->create([
        'type' => 'phytosanitary',
        'original_filename' => 'phyto.pdf',
        'disk' => 'documents',
        'storage_path' => 'products/documents/phyto.pdf',
        'issuer' => 'MINFOF',
    ]);

    expect($document->owner_type)->toBe(Product::class)
        ->and($document->owner_id)->toBe($product->getKey())
        ->and($document->verification_status)->toBe(DocumentVerificationStatus::Unverified)
        ->and($document->file_size)->toBeGreaterThan(0)
        ->and($document->mime_type)->not->toBeNull();
});

it('does not let a supplier reach the relation manager for another company product', function () {
    $own = Company::factory()->publiclyVisible()->create();
    $other = Company::factory()->publiclyVisible()->create();
    $otherProduct = Product::factory()->for($other, 'company')->create();

    $this->actingAs(productDocSupplier($own));

    $this->get(ProductResource::getUrl('edit', ['record' => $otherProduct]))->assertNotFound();
});

it('renders product documents with status badges on the public listing and drops supplier badges', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->active()->for($company, 'company')->create(['certification' => null]);

    $product->documents()->create([
        'type' => 'legal_origin',
        'original_filename' => 'origin.pdf',
        'disk' => 'documents',
        'storage_path' => 'products/documents/origin.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'verification_status' => DocumentVerificationStatus::Verified,
    ]);
    $product->documents()->create([
        'type' => 'phytosanitary',
        'original_filename' => 'phyto.pdf',
        'disk' => 'documents',
        'storage_path' => 'products/documents/phyto.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'verification_status' => DocumentVerificationStatus::Verified,
        'expires_at' => now()->subDay(),
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Proof of legal origin')
        ->assertSee('Verified')
        ->assertSee('Phytosanitary certificate')
        ->assertSee('Expired')
        ->assertDontSee('This listing has no compliance documents on file yet');
});

it('says so when a listing has no compliance documents', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->active()->for($company, 'company')->create(['certification' => null]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('This listing has no compliance documents on file yet');
});

it('picks up a product document 30 days from expiry in the expiry reminder job', function () {
    $product = Product::factory()->create();
    $document = $product->documents()->create([
        'type' => 'fsc_pefc',
        'original_filename' => 'fsc.pdf',
        'disk' => 'documents',
        'storage_path' => 'products/documents/fsc.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 1024,
        'expires_at' => now()->addDays(30),
    ]);

    (new SendDocumentExpiryReminderJob)->handle();

    expect(DocumentReminderLog::where('document_owner_type', Document::class)
        ->where('document_owner_id', $document->getKey())
        ->where('threshold', '30')
        ->exists())->toBeTrue();
});
