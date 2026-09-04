<?php

namespace Database\Factories;

use App\Enums\FraudSignalSeverity;
use App\Enums\FraudSignalStatus;
use App\Enums\FraudSignalType;
use App\Models\Company;
use App\Models\FraudSignal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FraudSignal> */
class FraudSignalFactory extends Factory
{
    protected $model = FraudSignal::class;

    public function definition(): array
    {
        return [
            'subject_type' => Company::class,
            'subject_id' => Company::factory(),
            'signal_type' => FraudSignalType::DuplicateCompany,
            'severity' => FraudSignalSeverity::Low,
            'details' => [],
            'status' => FraudSignalStatus::Open,
        ];
    }
}
