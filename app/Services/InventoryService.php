<?php

namespace App\Services;

use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Atomic reserve/restock against Inventory.quantity_available (brief §4,
 * gap-plan 1.5.6). Mirrors CertificateAllocationService's lockForUpdate()
 * pattern for the same "read remaining, then decrement" race condition.
 */
class InventoryService
{
    public function reserve(Inventory $inventory, float $quantity): Inventory
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Reserve quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($inventory, $quantity) {
            $locked = Inventory::query()->whereKey($inventory->getKey())->lockForUpdate()->firstOrFail();

            if ($quantity > $locked->quantity_available) {
                throw new RuntimeException("Reservation of {$quantity} {$locked->unit} exceeds available quantity of {$locked->quantity_available} {$locked->unit}.");
            }

            $locked->decrement('quantity_available', $quantity);

            return $locked->fresh();
        });
    }

    public function restock(Inventory $inventory, float $quantity): Inventory
    {
        if ($quantity <= 0) {
            throw new RuntimeException('Restock quantity must be greater than zero.');
        }

        $inventory->increment('quantity_available', $quantity);

        return $inventory->fresh();
    }
}
