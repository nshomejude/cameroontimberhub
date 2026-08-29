<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyInquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyInquiry>
 */
class CompanyInquiryFactory extends Factory
{
    protected $model = CompanyInquiry::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => $this->faker->name(),
            'email' => $this->faker->safeEmail(),
            'phone' => null,
            'message' => $this->faker->paragraph(),
            'status' => 'new',
            'ip_address' => $this->faker->ipv4(),
        ];
    }
}
