<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shipment;
use Illuminate\Support\Str;

/**
 * The one write path from a transport booking (an Order) to a Shipment
 * (gap-plan 1.5.10). Fleet linkage is entirely optional — mirrors
 * InventoryService's opt-in pattern: a booking with no vehicle/driver data
 * yet is a normal, fully functional Shipment, not an error.
 */
class ShipmentService
{
    public function createFromOrder(Order $order, array $data = []): Shipment
    {
        return Shipment::create([
            'order_id' => $order->getKey(),
            'waybill_number' => $data['waybill_number'] ?? $this->generateWaybillNumber(),
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'driver_id' => $data['driver_id'] ?? null,
            'origin' => $data['origin'] ?? null,
            'destination' => $data['destination'] ?? null,
        ]);
    }

    private function generateWaybillNumber(): string
    {
        do {
            $candidate = 'WB-'.strtoupper(Str::random(10));
        } while (Shipment::where('waybill_number', $candidate)->exists());

        return $candidate;
    }
}
