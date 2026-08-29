<?php

use App\Models\Inventory;
use App\Services\InventoryService;

beforeEach(function () {
    $this->service = app(InventoryService::class);
});

it('reserves (decrements) available quantity atomically', function () {
    $inventory = Inventory::factory()->create(['quantity_available' => 100]);

    $this->service->reserve($inventory, 30);

    expect($inventory->fresh()->quantity_available)->toEqual(70);
});

it('refuses to reserve more than what is available', function () {
    $inventory = Inventory::factory()->create(['quantity_available' => 20]);

    expect(fn () => $this->service->reserve($inventory, 30))
        ->toThrow(RuntimeException::class, 'exceeds available');

    expect($inventory->fresh()->quantity_available)->toEqual(20);
});

it('restocks (increments) available quantity', function () {
    $inventory = Inventory::factory()->create(['quantity_available' => 50]);

    $this->service->restock($inventory, 25);

    expect($inventory->fresh()->quantity_available)->toEqual(75);
});
