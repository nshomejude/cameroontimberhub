<?php

namespace Database\Factories;

use App\Enums\CheckpointStatus;
use App\Enums\VerificationStage;
use App\Models\Verification;
use App\Models\VerificationCheckpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VerificationCheckpoint> */
class VerificationCheckpointFactory extends Factory
{
    protected $model = VerificationCheckpoint::class;

    public function definition(): array
    {
        return [
            'verification_id' => Verification::factory(),
            'stage' => VerificationStage::Registered,
            'status' => CheckpointStatus::Pending,
        ];
    }
}
