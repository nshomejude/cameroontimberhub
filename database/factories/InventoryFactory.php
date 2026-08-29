<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inventory> */
class InventoryFactory extends Factory
{
    protected $model = Inventory::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'location' => $this->faker->randomElement(['Douala yard', 'Yaoundé warehouse', null]),
            'quantity_available' => $this->faker->randomFloat(2, 10, 500),
            'unit' => 'm3',
        ];
    }
}
