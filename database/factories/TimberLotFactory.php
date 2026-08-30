<?php

namespace Database\Factories;

use App\Enums\TimberLotStatus;
use App\Models\Company;
use App\Models\Species;
use App\Models\TimberLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TimberLot> */
class TimberLotFactory extends Factory
{
    protected $model = TimberLot::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'species_id' => Species::factory(),
            'product_form' => $this->faker->randomElement(['sawn_timber', 'logs', 'veneer', 'plywood']),
            'grade' => 'Select & Better',
            'quantity' => $this->faker->randomFloat(2, 10, 500),
            'volume_m3' => $this->faker->randomFloat(3, 10, 500),
            'unit' => 'm3',
            'origin_country' => 'CM',
            'origin_region' => $this->faker->randomElement(['East', 'South', 'Centre', 'Littoral']),
            'available_quantity' => $this->faker->randomFloat(2, 10, 500),
            'reserved_quantity' => 0,
            'price_currency' => 'XAF',
            'legality_evidence_status' => 'not_assessed',
            'traceability_status' => 'not_traceable',
            'inspection_status' => 'not_inspected',
            'status' => TimberLotStatus::Draft,
        ];
    }

    public function available(): static
    {
        return $this->state(['status' => TimberLotStatus::Available]);
    }
}
