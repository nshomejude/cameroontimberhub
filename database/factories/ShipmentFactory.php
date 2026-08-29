<?php

namespace Database\Factories;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * No default `order_id` — Order has no factory of its own (it is only ever
 * created through OrderService::createFromQuote()), so every test that uses
 * this factory must supply a real order id explicitly.
 */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'waybill_number' => 'WB-'.strtoupper(Str::random(10)),
            'vehicle_id' => null,
            'driver_id' => null,
            'origin' => fake()->city(),
            'destination' => fake()->city(),
        ];
    }
}
