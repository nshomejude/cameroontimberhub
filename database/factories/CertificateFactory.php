<?php

namespace Database\Factories;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Certificate> */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    public function definition(): array
    {
        return [
            'certificate_number' => 'TH-CMR-ORG-'.now()->year.'-'.strtoupper(Str::random(10)),
            'verification_token' => Str::random(48),
            'subject_type' => Product::class,
            'subject_id' => Product::factory(),
            'data' => [
                'product' => $this->faker->words(3, true),
                'origin' => ['country' => 'Cameroon', 'region' => $this->faker->state()],
            ],
            'version' => 1,
            'certified_quantity' => $this->faker->randomFloat(3, 10, 500),
            'quantity_unit' => 'm3',
            'status' => CertificateStatus::Draft,
        ];
    }
}
