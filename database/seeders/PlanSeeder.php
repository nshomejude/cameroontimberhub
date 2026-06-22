<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public const PLANS = [
        [
            'slug' => 'free', 'name' => 'Free', 'price_amount' => 0, 'sort_order' => 0,
            'description' => 'A basic public listing to get discovered.',
            'features' => ['max_gallery' => 3, 'verified_badge' => false, 'leads_receive' => false, 'featured' => false],
        ],
        [
            'slug' => 'professional', 'name' => 'Professional', 'price_amount' => 50000, 'sort_order' => 1,
            'description' => 'A verified profile with RFQ leads and a larger gallery.',
            'features' => ['max_gallery' => 10, 'verified_badge' => true, 'leads_receive' => true, 'featured' => false],
        ],
        [
            'slug' => 'enterprise', 'name' => 'Enterprise', 'price_amount' => 250000, 'sort_order' => 2,
            'description' => 'Everything in Professional plus featured placement and priority support.',
            'features' => ['max_gallery' => 30, 'verified_badge' => true, 'leads_receive' => true, 'featured' => true, 'api' => true],
        ],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], array_merge([
                'price_currency' => 'XAF',
                'billing_period' => 'yearly',
                'is_active' => true,
            ], $plan));
        }
    }
}
