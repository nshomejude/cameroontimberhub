<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'plan_id'    => Plan::factory(),
            'status'     => SubscriptionStatus::Active,
            'starts_at'  => now(),
            'ends_at'    => now()->addYear(),
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status'       => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status'  => SubscriptionStatus::Expired,
            'ends_at' => now()->subDay(),
        ]);
    }
}
