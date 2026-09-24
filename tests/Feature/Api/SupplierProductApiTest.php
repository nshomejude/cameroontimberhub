<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function supplierProductApiUser(): array
{
    $user = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();
    $company->users()->attach($user);

    return [$user, $company];
}

function supplierProductPayload(array $overrides = []): array
{
    $species = Species::factory()->create();

    return array_merge([
        'name' => 'Iroko Sawn Timber KD 50mm',
        'product_type' => 'sawn_timber',
        'species_id' => $species->id,
        'grade' => 'FAS',
        'price_amount' => 650000,
        'price_currency' => 'XAF',
        'price_unit' => 'm3',
    ], $overrides);
}

/* --------------------------------------------------------------- listing */

it('lists only the caller company own products, any status', function () {
    [$user, $company] = supplierProductApiUser();
    $other = Company::factory()->publiclyVisible()->create();

    $mine = Product::factory()->for($company)->create(['status' => 'draft']);
    Product::factory()->for($other)->create(['status' => 'active']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/products')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($mine->id)->and($ids)->toHaveCount(1);
});

it('filters the list by status', function () {
    [$user, $company] = supplierProductApiUser();
    $draft = Product::factory()->for($company)->create(['status' => 'draft']);
    Product::factory()->for($company)->create(['status' => 'active']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/products?status=draft')
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');

    expect($ids)->toContain($draft->id)->and($ids)->toHaveCount(1);
});

it('404s another company product instead of 403ing', function () {
    [$user] = supplierProductApiUser();
    $other = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->for($other)->create();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/supplier/products/{$product->id}")
        ->assertNotFound();
});

/* --------------------------------------------------------------- create */

it('creates a product as draft by default', function () {
    [$user, $company] = supplierProductApiUser();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/products', supplierProductPayload())
        ->assertCreated();

    expect($response->json('data.status'))->toBe('draft');

    $this->assertDatabaseHas('products', [
        'id' => $response->json('data.id'),
        'company_id' => $company->id,
        'status' => 'draft',
    ]);
});

it('rejects a create request missing required fields', function () {
    [$user] = supplierProductApiUser();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/products', ['description' => 'no name or type'])
        ->assertStatus(422);

    $details = $response->json('error.details');

    expect($details)->toHaveKeys(['name', 'product_type', 'species_id']);
});

it('does not require species_id for charcoal', function () {
    [$user] = supplierProductApiUser();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/products', [
            'name' => 'Charcoal briquettes',
            'product_type' => 'charcoal',
        ])
        ->assertCreated();
});

it('ignores a company_id supplied by the client', function () {
    [$user, $company] = supplierProductApiUser();
    $other = Company::factory()->publiclyVisible()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/products', supplierProductPayload(['company_id' => $other->id]))
        ->assertCreated();

    $this->assertDatabaseHas('products', ['id' => $response->json('data.id'), 'company_id' => $company->id]);
});

/* --------------------------------------------------------------- update */

it('updates a product scoped to the caller company', function () {
    [$user, $company] = supplierProductApiUser();
    $product = Product::factory()->for($company)->create(['name' => 'Old name', 'status' => 'draft']);

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/supplier/products/{$product->id}", ['name' => 'New name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New name');
});

it('404s updating another company product', function () {
    [$user] = supplierProductApiUser();
    $other = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->for($other)->create();

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/supplier/products/{$product->id}", ['name' => 'Hijacked'])
        ->assertNotFound();
});

/* --------------------------------------------------------------- submit */

it('submits a draft product to active', function () {
    [$user, $company] = supplierProductApiUser();
    $product = Product::factory()->for($company)->create(['status' => 'draft']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('blocks submitting an already active product', function () {
    [$user, $company] = supplierProductApiUser();
    $product = Product::factory()->for($company)->create(['status' => 'active']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/submit")
        ->assertStatus(409);
});

it('blocks submitting an archived product', function () {
    [$user, $company] = supplierProductApiUser();
    $product = Product::factory()->for($company)->create(['status' => 'archived']);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/submit")
        ->assertStatus(409);
});

/* --------------------------------------------------------------- images */

it('uploads a product image', function () {
    Storage::fake('public');
    [$user, $company] = supplierProductApiUser();
    $product = Product::factory()->for($company)->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->image('photo.jpg'),
        ])
        ->assertCreated();

    $product->refresh();

    expect($product->primary_image_path)->toStartWith('products/');
    Storage::disk('public')->assertExists($product->primary_image_path);

    // Regression: publicImage() used to only resolve public/img/... paths,
    // so an image uploaded through this endpoint (public disk, products/...)
    // rendered as a broken image everywhere primaryImageUrl() is read.
    expect($product->primaryImageUrl())
        ->toBe(Storage::disk('public')->url($product->primary_image_path));
});

it('rejects a non-image file upload', function () {
    Storage::fake('public');
    [$user, $company] = supplierProductApiUser();
    $product = Product::factory()->for($company)->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])
        ->assertStatus(422);

    expect($response->json('error.details'))->toHaveKey('image');
});

/* --------------------------------------------------------------- options */

it('returns real enum and species data from the options endpoint', function () {
    [$user] = supplierProductApiUser();
    $species = Species::factory()->create(['common_name' => 'Sapele']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/products/options')
        ->assertOk();

    $speciesNames = collect($response->json('data.species'))->pluck('common_name');
    $speciesIds = collect($response->json('data.species'))->pluck('id');

    expect($speciesNames)->toContain('Sapele')
        ->and($speciesIds)->toContain($species->id)
        ->and(collect($response->json('data.product_types'))->pluck('value'))->toContain('sawn_timber');
});

/* --------------------------------------------------------------- actions flags */

it('flips the can_submit action flag across status transitions', function () {
    [$user, $company] = supplierProductApiUser();
    $draft = Product::factory()->for($company)->create(['status' => 'draft']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/supplier/products/{$draft->id}")
        ->assertOk();

    expect($response->json('data.actions.can_edit'))->toBeTrue()
        ->and($response->json('data.actions.can_submit'))->toBeTrue();

    $draft->update(['status' => 'active']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/supplier/products/{$draft->id}")
        ->assertOk();

    expect($response->json('data.actions.can_edit'))->toBeTrue()
        ->and($response->json('data.actions.can_submit'))->toBeFalse();
});

/* --------------------------------------------------------------- species_id fix */

it('exposes species_id on rfq items so a supplier quote can reference it', function () {
    [$user, $company] = supplierProductApiUser();
    $species = Species::factory()->create();

    $rfq = \App\Models\Rfq::factory()->approved()->create();
    $rfq->items()->create(['species_id' => $species->id, 'species_text' => $species->common_name, 'form' => 'sawn', 'quantity' => 10, 'unit' => 'm3']);

    \App\Models\RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/supplier/rfqs/{$rfq->reference_code}")
        ->assertOk();

    expect($response->json('data.items.0.species_id'))->toBe($species->id);
});

/* --------------------------------------------------------------- auth gates */

it('rejects a guest with 401', function () {
    $this->getJson('/api/v1/supplier/products')->assertUnauthorized();
});

it('rejects a buyer with no company with 403', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/products')
        ->assertForbidden();
});
