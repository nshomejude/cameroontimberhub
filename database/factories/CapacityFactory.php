<?php

namespace Database\Factories;

use App\Models\Capacity;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Capacity> */
class CapacityFactory extends Factory
{
    protected $model = Capacity::class;

    public function definition(): array
    {
        return [
            'owner_type' => Company::class,
            'owner_id' => Company::factory(),
            'capability' => $this->faker->randomElement(['Kiln drying', 'Sawing', 'Planing', 'Furniture assembly', 'CNC machining']),
            'quantity' => $this->faker->randomFloat(2, 10, 1000),
            'unit' => $this->faker->randomElement(['m3', 'units', 'jobs']),
            'period' => $this->faker->randomElement(['month', 'week', 'quarter']),
        ];
    }
}
