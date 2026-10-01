<?php

use App\Enums\CompanyStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Filament\Exporter\Resources\Products\Pages\CreateProduct;
use App\Filament\Exporter\Resources\Products\Pages\EditProduct;
use App\Filament\Exporter\Resources\Products\Pages\ListProducts;
use App\Filament\Exporter\Resources\Products\ProductResource;
use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function publishingMember(Company $company, string $role = 'owner'): User
{
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => $role, 'is_primary' => true]);

    return $user;
}

function readyDraft(Company $company, array $overrides = []): Product
{
    return Product::factory()->for($company)->create(array_merge([
        'status' => 'draft',
        'description' => 'Kiln-dried Iroko boards, FAS grade, export ready.',
        'primary_image_path' => 'products/iroko.jpg',
    ], $overrides));
}

/* ---------------------------------------------------- 1. visibility gaps */

it('reports no visibility gaps for a publicly visible company', function () {
    expect(Company::factory()->publiclyVisible()->create()->publicVisibilityGaps())->toBe([]);
});

it('lists every missing public-visibility condition', function () {
    $company = Company::factory()->create(['status' => 'pending', 'logo_path' => null]);

    $gaps = $company->publicVisibilityGaps();

    expect(implode(' | ', $gaps))
        ->toContain('verification')
        ->toContain('logo')
        ->toContain('badge');
    expect(Company::query()->whereKey($company->id)->publiclyVisible()->exists())->toBeFalse();
});

it('exposes visibility on the supplier API resource', function () {
    $company = Company::factory()->create(['status' => 'pending']);
    $user = publishingMember($company);
    $product = readyDraft($company);

    $res = $this->actingAs($user, 'sanctum')->getJson("/api/v1/supplier/products/{$product->id}")->assertOk();

    expect($res->json('data.visibility.public'))->toBeFalse()
        ->and($res->json('data.visibility.missing'))->not->toBeEmpty();

    $visible = Company::factory()->publiclyVisible()->create();
    $owner = publishingMember($visible);
    $live = readyDraft($visible, ['status' => 'active']);

    $this->actingAs($owner, 'sanctum')->getJson("/api/v1/supplier/products/{$live->id}")
        ->assertJsonPath('data.visibility.public', true)
        ->assertJsonPath('data.visibility.missing', []);
});

it('shows the not-visible banner on the exporter products list', function () {
    $company = Company::factory()->create(['status' => 'pending']);
    $this->actingAs(publishingMember($company));

    Livewire::test(ListProducts::class)->assertSee('not visible to buyers yet');
});

it('lets a company member preview their own non-public product, and 404s everyone else', function () {
    $company = Company::factory()->create(['status' => 'pending']);
    $member = publishingMember($company, 'member');
    $product = readyDraft($company, ['status' => 'active']);

    $this->get(route('products.show', $product->slug))->assertNotFound();
    $this->actingAs(User::factory()->create())->get(route('products.show', $product->slug))->assertNotFound();

    $this->actingAs($member)->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Preview — not visible to buyers', false)
        ->assertSee('noindex', false);
});

/* ---------------------------------------------------- 2. company status */

it('blocks publishing for suspended, rejected and archived companies via the API', function (string $status) {
    $company = Company::factory()->create(['status' => $status]);
    $user = publishingMember($company);
    $product = readyDraft($company);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/submit")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'company_cannot_publish');

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/supplier/products/{$product->id}", ['status' => 'active'])
        ->assertStatus(409);

    expect($product->fresh()->status)->toBe(ProductStatus::Draft);
})->with(['suspended', 'rejected', 'archived']);

it('lets a pending company publish (hidden until verified)', function () {
    $company = Company::factory()->create(['status' => 'pending']);
    $user = publishingMember($company);
    $product = readyDraft($company);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.visibility.public', false);
});

it('refuses the Filament publish toggle for a suspended company', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $user = publishingMember($company);
    $product = readyDraft($company);
    $company->update(['status' => CompanyStatus::Suspended]);

    $this->actingAs($user);

    Livewire::test(ListProducts::class)->callTableAction('toggleStatus', $product);

    expect($product->fresh()->status)->toBe(ProductStatus::Draft);
});

it('publishes via the Filament toggle when everything is in order', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = readyDraft($company);
    $this->actingAs(publishingMember($company));

    Livewire::test(ListProducts::class)->callTableAction('toggleStatus', $product)->assertNotified();

    expect($product->fresh()->status)->toBe(ProductStatus::Active);
});

/* ---------------------------------------------------- 3. roles */

it('denies plain members product writes in Filament and the API', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $member = publishingMember($company, 'member');
    $product = readyDraft($company);

    $this->actingAs($member);
    expect(ProductResource::canCreate())->toBeFalse()
        ->and(ProductResource::canEdit($product))->toBeFalse()
        ->and(ProductResource::canDelete($product))->toBeFalse()
        ->and(ProductResource::canViewAny())->toBeTrue();

    $this->actingAs($member, 'sanctum')
        ->postJson('/api/v1/supplier/products', ['name' => 'X', 'product_type' => 'charcoal'])
        ->assertForbidden();
    $this->actingAs($member, 'sanctum')
        ->patchJson("/api/v1/supplier/products/{$product->id}", ['name' => 'Y'])
        ->assertForbidden();
    $this->actingAs($member, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/submit")
        ->assertForbidden();
    $this->actingAs($member, 'sanctum')
        ->deleteJson("/api/v1/supplier/products/{$product->id}")
        ->assertForbidden();
    $this->actingAs($member, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg')])
        ->assertForbidden();

    $this->actingAs($member, 'sanctum')->getJson("/api/v1/supplier/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.actions.can_edit', false);

    expect($product->fresh()->name)->not->toBe('Y');
});

it('lets a manager manage products', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $manager = publishingMember($company, 'manager');
    $this->actingAs($manager, 'sanctum')
        ->postJson('/api/v1/supplier/products', ['name' => 'Charcoal', 'product_type' => 'charcoal'])
        ->assertCreated();
});

/* ---------------------------------------------------- 4. rate limits */

it('allows more than three product creations per hour', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $user = publishingMember($company);

    foreach (range(1, 5) as $i) {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/supplier/products', ['name' => "Charcoal {$i}", 'product_type' => 'charcoal'])
            ->assertCreated();
    }
});

/* ---------------------------------------------------- 5. nulls & validation */

it('applies defaults instead of 500ing on explicit nulls', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $user = publishingMember($company);

    $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/supplier/products', [
        'name' => 'Charcoal', 'product_type' => 'charcoal',
        'price_currency' => null, 'price_unit' => null, 'moq_unit' => null, 'origin' => null,
    ])->assertCreated();

    $product = Product::findOrFail($res->json('data.id'));
    expect($product->price_currency)->toBe('XAF')
        ->and($product->price_unit->value)->toBe('m3')
        ->and($product->moq_unit->value)->toBe('m3')
        ->and($product->origin)->toBe('Cameroon');

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/supplier/products/{$product->id}", ['price_currency' => null, 'origin' => null])
        ->assertOk();
    expect($product->fresh()->origin)->toBe('Cameroon');
});

it('validates currency, price ceiling and dimension ranges', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $user = publishingMember($company);

    $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/supplier/products', [
        'name' => 'Charcoal', 'product_type' => 'charcoal',
        'price_currency' => 'EURO', 'price_amount' => 10 ** 13,
        'width_min_mm' => 200, 'width_max_mm' => 100,
        'length_min_m' => 5, 'length_max_m' => 2,
    ])->assertStatus(422);

    expect($res->json('error.details'))->toHaveKeys(['price_currency', 'price_amount', 'width_max_mm', 'length_max_m']);

    $ok = $this->actingAs($user, 'sanctum')->postJson('/api/v1/supplier/products', [
        'name' => 'Charcoal', 'product_type' => 'charcoal', 'price_currency' => 'usd',
    ])->assertCreated();
    expect(Product::find($ok->json('data.id'))->price_currency)->toBe('USD');
});

/* ---------------------------------------------------- 6. category */

it('assigns category_id from product_type on create', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $user = publishingMember($company);

    $res = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/products', ['name' => 'Charcoal', 'product_type' => 'charcoal'])
        ->assertCreated();

    $residue = Category::query()->where('kind', 'form')->where('slug', 'residue')->value('id');
    expect(Product::find($res->json('data.id'))->category_id)->toBe($residue);
});

/* ---------------------------------------------------- 8. quality minimum */

it('requires description and primary image to publish', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $user = publishingMember($company);
    $product = readyDraft($company, ['description' => 'Too short', 'primary_image_path' => null]);

    $res = $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/submit")
        ->assertStatus(422);

    expect($res->json('error.details'))->toHaveKeys(['description', 'primary_image_path']);
    expect($product->fresh()->status)->toBe(ProductStatus::Draft);
});

it('rejects creating straight into active from Filament without the quality minimum', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $species = Species::factory()->create();
    $this->actingAs(publishingMember($company));

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Iroko',
            'product_type' => ProductType::SawnTimber->value,
            'species_id' => $species->id,
            'description' => 'short',
            'status' => ProductStatus::Active->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['description', 'primary_image_path']);

    expect(Product::where('name', 'Iroko')->exists())->toBeFalse();
});

/* ---------------------------------------------------- 9. images */

it('rejects oversized images and deletes the replaced primary image', function () {
    Storage::fake('public');
    $company = Company::factory()->publiclyVisible()->create();
    $user = publishingMember($company);
    $product = readyDraft($company);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('big.jpg')->size(6000)])
        ->assertStatus(422);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('a.jpg')])
        ->assertCreated();
    $first = $product->fresh()->primary_image_path;
    Storage::disk('public')->assertExists($first);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('b.jpg')])
        ->assertCreated();

    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($product->fresh()->primary_image_path);
});

/* ---------------------------------------------------- 10. archived */

it('refuses archived -> active through API update and the Filament edit form', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $user = publishingMember($company);
    $product = readyDraft($company, ['status' => 'archived']);

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/supplier/products/{$product->id}", ['status' => 'active'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'product_archived');

    $this->actingAs($user);
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['status' => ProductStatus::Active->value])
        ->call('save');

    expect($product->fresh()->status)->toBe(ProductStatus::Archived);
});
