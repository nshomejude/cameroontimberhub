<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Filament\Exporter\Resources\Products\Pages\CreateProduct;
use App\Filament\Exporter\Resources\Products\Pages\ListProducts;
use App\Filament\Exporter\Resources\Products\ProductResource;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function exporterForProductTest(Company $company): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

it('lets a company user create a product scoped to their own company', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $species = Species::factory()->create();

    $this->actingAs(exporterForProductTest($company));

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Iroko Sawn Timber KD 50mm',
            'product_type' => ProductType::SawnTimber->value,
            'species_id' => $species->getKey(),
            'price_amount' => 500000,
            'price_currency' => 'XAF',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('name', 'Iroko Sawn Timber KD 50mm')->firstOrFail();

    expect($product->company_id)->toBe($company->getKey())
        ->and($product->status)->toBe(ProductStatus::Draft);
});

it('never lets a crafted request assign a product to another company', function () {
    $own = Company::factory()->publiclyVisible()->create();
    $other = Company::factory()->publiclyVisible()->create();
    $species = Species::factory()->create();

    $this->actingAs(exporterForProductTest($own));

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Crafted Product',
            'product_type' => ProductType::SawnTimber->value,
            'species_id' => $species->getKey(),
            'company_id' => $other->getKey(), // not a real form field, should be ignored
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('name', 'Crafted Product')->firstOrFail();

    expect($product->company_id)->toBe($own->getKey())
        ->and($product->company_id)->not->toBe($other->getKey());
});

it('only shows a company its own products in the list', function () {
    $own = Company::factory()->publiclyVisible()->create();
    $other = Company::factory()->publiclyVisible()->create();

    $ownProduct = Product::factory()->for($own, 'company')->create(['name' => 'Own Product']);
    Product::factory()->for($other, 'company')->create(['name' => 'Other Product']);

    $this->actingAs(exporterForProductTest($own));

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$ownProduct])
        ->assertCountTableRecords(1);
});

it('blocks a user with no company from accessing the products resource', function () {
    $this->actingAs(User::factory()->create());

    expect(ProductResource::canViewAny())->toBeFalse();

    $this->get('/dashboard/products')->assertForbidden();
});

it('shows dimensional fields for sawn timber but hides them for charcoal', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $this->actingAs(exporterForProductTest($company));

    Livewire::test(CreateProduct::class)
        ->fillForm(['product_type' => ProductType::SawnTimber->value])
        ->assertFormFieldIsVisible('thickness_mm')
        ->assertFormFieldIsVisible('species_id')
        ->fillForm(['product_type' => ProductType::Charcoal->value])
        ->assertFormFieldIsHidden('thickness_mm')
        ->assertFormFieldIsHidden('species_id');
});
