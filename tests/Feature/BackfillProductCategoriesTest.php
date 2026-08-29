<?php

use App\Enums\ProductType;
use App\Models\Category;
use App\Models\Product;
use App\Support\CategoryMigrationMap;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('backfills category_id from product_type via CategoryMigrationMap', function () {
    $raw = Category::query()->where('slug', 'raw')->firstOrFail();
    $product = Product::factory()->create([
        'product_type' => ProductType::Logs,
        'category_id' => null,
    ]);

    $this->artisan('products:backfill-categories')->assertExitCode(0);

    expect($product->fresh()->category_id)->toBe($raw->id);
});

it('is idempotent and never overwrites an already-set category_id', function () {
    $finished = Category::query()->where('slug', 'finished')->firstOrFail();
    $raw = Category::query()->where('slug', 'raw')->firstOrFail();

    $product = Product::factory()->create([
        'product_type' => ProductType::Logs,
        'category_id' => $finished->id,
    ]);

    $this->artisan('products:backfill-categories')->assertExitCode(0);

    expect($product->fresh()->category_id)->toBe($finished->id)
        ->and($product->fresh()->category_id)->not->toBe($raw->id);
});

it('leaves products whose product_type has no map entry uncategorized', function () {
    // Every ProductType value must be mapped (see CategoryMigrationMap); this
    // test guards against the map's coverage silently regressing.
    expect(array_diff(
        array_map(fn ($c) => $c->value, ProductType::cases()),
        array_keys(CategoryMigrationMap::MAP)
    ))->toBe([]);
});
