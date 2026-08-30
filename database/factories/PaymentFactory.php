<?php

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'plan_id' => Plan::factory(),
            'subscription_id' => null,
            'provider' => $this->faker->randomElement(PaymentProvider::cases()),
            'provider_reference' => null,
            'amount' => $this->faker->numberBetween(5, 500) * 1000,
            'currency' => 'XAF',
            'status' => PaymentStatus::Pending,
            'metadata' => [],
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Completed,
            'provider_reference' => $this->faker->uuid(),
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(['status' => PaymentStatus::Failed]);
    }
}
