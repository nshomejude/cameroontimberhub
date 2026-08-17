<?php

namespace Database\Factories;

use App\Enums\RfqStatus;
use App\Models\Rfq;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Rfq> */
class RfqFactory extends Factory
{
    protected $model = Rfq::class;

    public function definition(): array
    {
        return [
            'reference_code'            => 'RFQ-'.date('Y').'-'.Str::upper(Str::random(5)),
            'buyer_name'                => $this->faker->name(),
            'buyer_company'             => $this->faker->optional()->company(),
            'buyer_email'               => $this->faker->safeEmail(),
            'buyer_country_code'        => $this->faker->randomElement(['FR', 'DE', 'GB', 'NL', 'US', 'CN']),
            'destination_country_code'  => $this->faker->randomElement(['FR', 'DE', 'GB', 'NL', 'US', 'CN']),
            'notes'                     => $this->faker->paragraph(3),
            'status'                    => RfqStatus::New,
            'visibility'                => 'public',
            'spam_score'                => 0,
            'is_spam'                   => false,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => ['email_verified_at' => now()]);
    }

    public function approved(): static
    {
        return $this->verified()->state(fn () => ['status' => RfqStatus::Approved]);
    }

    public function spam(): static
    {
        return $this->state(fn () => ['status' => RfqStatus::Spam, 'is_spam' => true, 'spam_score' => 80]);
    }
}
