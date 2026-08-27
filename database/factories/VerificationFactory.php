<?php

namespace Database\Factories;

use App\Enums\VerificationStage;
use App\Models\Product;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Verification> */
class VerificationFactory extends Factory
{
    protected $model = Verification::class;

    public function definition(): array
    {
        return [
            'entity_type' => Product::class,
            'entity_id' => Product::factory(),
            'stage' => VerificationStage::Registered,
        ];
    }
}
