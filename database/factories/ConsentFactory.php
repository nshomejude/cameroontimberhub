<?php

namespace Database\Factories;

use App\Enums\ConsentPurpose;
use App\Models\Consent;
use App\Models\Rfq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consent>
 */
class ConsentFactory extends Factory
{
    protected $model = Consent::class;

    public function definition(): array
    {
        return [
            'subject_type' => Rfq::class,
            'subject_id' => Rfq::factory(),
            'purpose' => ConsentPurpose::RfqExporterSharing,
            'scope' => ['shared_with' => 'verified_exporters', 'contact_channel' => 'email'],
            'granted_at' => now(),
            'evidence' => [
                'ip_address' => $this->faker->ipv4(),
                'user_agent' => $this->faker->userAgent(),
                'captured_at' => now()->toIso8601String(),
            ],
        ];
    }
}
