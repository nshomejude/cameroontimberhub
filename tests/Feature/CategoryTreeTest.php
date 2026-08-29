<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the six top-level form categories and six sector categories', function () {
    expect(Category::query()->where('kind', 'form')->count())->toBe(6);
    expect(Category::query()->where('kind', 'sector')->count())->toBe(6);

    $slugs = Category::query()->pluck('slug')->all();

    foreach (['raw', 'secondary-processed', 'finished', 'construction', 'residue', 'equipment'] as $slug) {
        expect($slugs)->toContain($slug);
    }

    foreach (['hospitality', 'education', 'healthcare', 'office', 'residential', 'interior-design'] as $slug) {
        expect($slugs)->toContain($slug);
    }
});

it('every seeded category is top-level (no parent)', function () {
    expect(Category::query()->whereNotNull('parent_id')->count())->toBe(0);
});

it('supports a self-referencing parent/children tree', function () {
    $root = Category::factory()->create(['parent_id' => null]);
    $child = Category::factory()->create(['parent_id' => $root->id]);

    expect($child->parent->id)->toBe($root->id);
    expect($root->children->pluck('id')->all())->toBe([$child->id]);
});

it('lets a product optionally belong to a category', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->create(['category_id' => $category->id]);

    expect($product->fresh()->category->id)->toBe($category->id);
});

it('leaves category_id nullable for products with no category assigned', function () {
    $product = Product::factory()->create(['category_id' => null]);

    expect($product->fresh()->category)->toBeNull();
});
