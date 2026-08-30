<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\CarbonProject;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CarbonProject> */
class CarbonProjectFactory extends Factory
{
    protected $model = CarbonProject::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => $this->faker->words(3, true).' project',
            'project_type' => $this->faker->randomElement([
                'reforestation', 'afforestation', 'avoided_deforestation', 'agroforestry',
            ]),
            'region' => $this->faker->city(),
            'area_hectares' => $this->faker->randomFloat(2, 10, 5000),
            'estimated_credits_per_year' => $this->faker->randomFloat(2, 100, 100000),
            'description' => $this->faker->sentence(),
            'status' => ProductStatus::Draft,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Active]);
    }
}
