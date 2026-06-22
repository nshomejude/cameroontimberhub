<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyContact> */
class CompanyContactFactory extends Factory
{
    protected $model = CompanyContact::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => $this->faker->name(),
            'title' => $this->faker->jobTitle(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->numerify('+237 6########'),
            'whatsapp' => null,
            'is_public' => true,
            'sort_order' => 0,
        ];
    }

    public function private(): static
    {
        return $this->state(['is_public' => false]);
    }
}
