<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RegulatorySourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'authority' => $this->faker->company(),
            'instrument_name' => $this->faker->sentence(3),
            'jurisdiction' => $this->faker->countryCode(),
            'legal_review_status' => 'pending',
        ];
    }
}
