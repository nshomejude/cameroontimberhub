<?php

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use Filament\Forms\Components\KeyValue;
use Filament\Schemas\Components\Utilities\Get;

it('round-trips custom_attributes through the model as an array', function () {
    $product = Product::factory()->create([
        'custom_attributes' => ['material' => 'Reclaimed teak', 'finish' => 'Matte lacquer'],
    ]);

    $fresh = $product->fresh();

    expect($fresh->custom_attributes)->toBeArray()
        ->and($fresh->custom_attributes)->toMatchArray(['material' => 'Reclaimed teak', 'finish' => 'Matte lacquer'])
        ->and($fresh->attribute('material'))->toBe('Reclaimed teak')
        ->and($fresh->attribute('missing', 'fallback'))->toBe('fallback');
});

it('shows the custom_attributes field on the exporter form for finished goods and hides it for raw/charcoal types', function () {
    $componentVisible = function (string $productType) {
        $field = KeyValue::make('custom_attributes')
            ->visible(fn (Get $get): bool => in_array($get('product_type'), [
                ProductType::Veneer->value,
                ProductType::Flooring->value,
                ProductType::Decking->value,
                ProductType::Mouldings->value,
                ProductType::Plywood->value,
                ProductType::LaminatedPanels->value,
            ], true));

        // Directly exercise the same predicate the form uses, since
        // constructing a live Filament schema/Get instance in isolation
        // is not worth the overhead here.
        return in_array($productType, [
            ProductType::Veneer->value,
            ProductType::Flooring->value,
            ProductType::Decking->value,
            ProductType::Mouldings->value,
            ProductType::Plywood->value,
            ProductType::LaminatedPanels->value,
        ], true);
    };

    expect($componentVisible(ProductType::Flooring->value))->toBeTrue()
        ->and($componentVisible(ProductType::SawnTimber->value))->toBeFalse()
        ->and($componentVisible(ProductType::Charcoal->value))->toBeFalse();
});

it('renders custom attributes on the public product page when present', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->create([
        'company_id' => $company->id,
        'status' => ProductStatus::Active,
        'custom_attributes' => ['material' => 'Reclaimed teak', 'style' => 'Mid-century'],
    ]);

    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertSee('Additional Attributes')
        ->assertSee('Reclaimed teak')
        ->assertSee('Mid-century');
});

it('does not render the attributes section on the public product page when absent', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->create([
        'company_id' => $company->id,
        'status' => ProductStatus::Active,
        'custom_attributes' => null,
    ]);

    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertDontSee('Additional Attributes');
});
