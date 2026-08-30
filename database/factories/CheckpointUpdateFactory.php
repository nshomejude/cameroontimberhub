<?php

namespace Database\Factories;

use App\Enums\TrackingCheckpointStatus;
use App\Models\CheckpointUpdate;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CheckpointUpdate>
 */
class CheckpointUpdateFactory extends Factory
{
    protected $model = CheckpointUpdate::class;

    public function definition(): array
    {
        return [
            'trackable_type' => Company::class,
            'trackable_id' => Company::factory(),
            'tracking_token' => str()->random(48),
            'status' => TrackingCheckpointStatus::Dispatched->value,
            'location' => $this->faker->city(),
            'latitude' => $this->faker->latitude(),
            'longitude' => $this->faker->longitude(),
            'photo_path' => null,
            'notes' => $this->faker->sentence(),
            'recorded_by' => null,
        ];
    }
}
