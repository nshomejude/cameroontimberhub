<?php

namespace Database\Factories;

use App\Enums\QuoteStatus;
use App\Models\Company;
use App\Models\Quote;
use App\Models\Rfq;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Quote> */
class QuoteFactory extends Factory
{
    protected $model = Quote::class;

    public function definition(): array
    {
        return [
            'rfq_id' => Rfq::factory()->approved(),
            'company_id' => Company::factory()->verified(),
            'reference_code' => 'QTE-'.date('Y').'-'.Str::upper(Str::random(5)),
            'status' => QuoteStatus::Draft,
            'currency' => 'USD',
            'subtotal_amount' => 0,
            'total_amount' => 0,
            'incoterm' => 'FOB',
            'lead_time_days' => $this->faker->numberBetween(10, 60),
            'validity_days' => 30,
            'valid_until' => now()->addDays(30)->toDateString(),
            'payment_terms' => '30% advance, 70% on delivery',
            'notes' => $this->faker->sentence(12),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => QuoteStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => QuoteStatus::Submitted,
            'submitted_at' => now()->subDays(60),
            'valid_until' => now()->subDay()->toDateString(),
        ]);
    }
}
