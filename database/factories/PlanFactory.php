<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->randomElement(['Starter', 'Professional', 'Enterprise', 'Premium', 'Basic']);

        return [
            'slug'           => Str::slug($name),
            'name'           => $name,
            'description'    => $this->faker->sentence(),
            'price_amount'   => $this->faker->randomElement([0, 50_000, 150_000, 300_000]),
            'price_currency' => 'XAF',
            'billing_period' => 'monthly',
            'features'       => ['leads_receive' => true, 'directory_listing' => true],
            'is_active'      => true,
            'sort_order'     => $this->faker->numberBetween(0, 10),
        ];
    }

    public function free(): static
    {
        return $this->state(fn () => ['price_amount' => 0, 'features' => ['directory_listing' => true]]);
    }

    public function professional(): static
    {
        return $this->state(fn () => [
            'slug'     => 'professional',
            'name'     => 'Professional',
            'features' => ['leads_receive' => true, 'directory_listing' => true, 'priority_ranking' => true],
        ]);
    }
}
