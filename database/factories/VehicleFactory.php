<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vehicle> */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'registration_number' => 'CM-'.$this->faker->unique()->numerify('####').'-'.strtoupper($this->faker->lexify('??')),
            'type' => $this->faker->randomElement(['truck', 'pickup', 'trailer', 'van']),
            'capacity_tonnes' => $this->faker->randomFloat(2, 1, 30),
            'is_active' => true,
        ];
    }
}
