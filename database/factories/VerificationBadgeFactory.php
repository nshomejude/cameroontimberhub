<?php

namespace Database\Factories;

use App\Enums\BadgeStatus;
use App\Enums\BadgeType;
use App\Models\Company;
use App\Models\VerificationBadge;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VerificationBadge> */
class VerificationBadgeFactory extends Factory
{
    protected $model = VerificationBadge::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'badge_type' => BadgeType::VerifiedExporter,
            'status' => BadgeStatus::Active,
            'issued_at' => now(),
            'valid_until' => now()->addYear()->toDateString(),
            'is_public' => true,
            'reference_code' => strtoupper($this->faker->unique()->bothify('CTH-########')),
        ];
    }

    public function expired(): static
    {
        return $this->state([
            'valid_until' => now()->subDay()->toDateString(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state([
            'status' => BadgeStatus::Revoked,
            'revoked_at' => now(),
            'revoked_reason' => 'Test revocation',
        ]);
    }
}
