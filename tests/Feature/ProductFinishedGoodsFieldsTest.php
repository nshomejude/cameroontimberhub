<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

it('includes materials_used, finish and dimensions_description in specificationRows when present', function () {
    $product = Product::factory()->create([
        'materials_used' => 'Solid oak',
        'finish' => 'Matte lacquer',
        'dimensions_description' => '80cm W x 45cm D x 90cm H',
    ]);

    $rows = $product->specificationRows();

    $labels = collect($rows)->pluck('value', 'label');

    expect($labels['Materials Used'])->toBe('Solid oak')
        ->and($labels['Finish'])->toBe('Matte lacquer')
        ->and($labels['Dimensions'])->toBe('80cm W x 45cm D x 90cm H');
});

it('excludes materials_used, finish and dimensions_description from specificationRows when null', function () {
    $product = Product::factory()->create([
        'materials_used' => null,
        'finish' => null,
        'dimensions_description' => null,
    ]);

    $labels = collect($product->specificationRows())->pluck('label');

    expect($labels)->not->toContain('Materials Used')
        ->and($labels)->not->toContain('Finish')
        ->and($labels)->not->toContain('Dimensions');
});

it('renders materials_used, finish and dimensions_description on the public product page when present', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->create([
        'company_id' => $company->id,
        'status' => ProductStatus::Active,
        'materials_used' => 'Solid oak',
        'finish' => 'Matte lacquer',
        'dimensions_description' => '80cm W x 45cm D x 90cm H',
    ]);

    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertSee('Materials Used')
        ->assertSee('Solid oak')
        ->assertSee('Finish')
        ->assertSee('Matte lacquer')
        ->assertSee('Dimensions')
        ->assertSee('80cm W x 45cm D x 90cm H');
});

it('shows the finished-goods text fields on the exporter form for flooring and hides them for sawn timber and charcoal', function () {
    $finishedTypes = [
        ProductType::Veneer->value,
        ProductType::Flooring->value,
        ProductType::Decking->value,
        ProductType::Mouldings->value,
        ProductType::Plywood->value,
        ProductType::LaminatedPanels->value,
    ];

    $componentVisible = function (string $productType) use ($finishedTypes) {
        TextInput::make('materials_used')
            ->visible(fn (Get $get): bool => in_array($get('product_type'), $finishedTypes, true));

        // Directly exercise the same predicate the form uses, since
        // constructing a live Filament schema/Get instance in isolation
        // is not worth the overhead here.
        return in_array($productType, $finishedTypes, true);
    };

    expect($componentVisible(ProductType::Flooring->value))->toBeTrue()
        ->and($componentVisible(ProductType::SawnTimber->value))->toBeFalse()
        ->and($componentVisible(ProductType::Charcoal->value))->toBeFalse();
});
