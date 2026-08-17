<?php

namespace Database\Factories;

use App\Enums\BadgeType;
use App\Enums\VerificationRequestStatus;
use App\Models\Company;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VerificationRequest> */
class VerificationRequestFactory extends Factory
{
    protected $model = VerificationRequest::class;

    public function definition(): array
    {
        return [
            'company_id'       => Company::factory(),
            'type'             => 'standard',
            'status'           => VerificationRequestStatus::Pending,
            'requested_badges' => [BadgeType::VerifiedCompany->value],
        ];
    }

    public function inReview(): static
    {
        return $this->state(fn () => ['status' => VerificationRequestStatus::InReview]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status'     => VerificationRequestStatus::Approved,
            'decided_at' => now(),
        ]);
    }

    public function rejected(string $notes = 'Documents insufficient.'): static
    {
        return $this->state(fn () => [
            'status'         => VerificationRequestStatus::Rejected,
            'decided_at'     => now(),
            'decision_notes' => $notes,
        ]);
    }
}
