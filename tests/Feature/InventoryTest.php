<?php

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('records available quantity for a product at a location', function () {
    $product = Product::factory()->create();

    $inventory = Inventory::create([
        'product_id' => $product->id,
        'location' => 'Douala yard',
        'quantity_available' => 100,
        'unit' => 'm3',
    ]);

    expect($inventory->fresh())->not->toBeNull()
        ->and($inventory->product)->toBeInstanceOf(Product::class);
});

it('rejects a negative quantity_available via the CHECK constraint', function () {
    $product = Product::factory()->create();

    expect(fn () => DB::table('inventory')->insert([
        'product_id' => $product->id,
        'location' => 'Douala yard',
        'quantity_available' => -1,
        'unit' => 'm3',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
