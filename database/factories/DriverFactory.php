<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Driver> */
class DriverFactory extends Factory
{
    protected $model = Driver::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => $this->faker->name(),
            'license_number' => strtoupper($this->faker->unique()->bothify('DL-#####??')),
            'phone' => $this->faker->e164PhoneNumber(),
            'is_active' => true,
        ];
    }
}
