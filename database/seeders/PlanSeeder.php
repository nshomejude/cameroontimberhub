<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public const PLANS = [
        [
            'slug' => 'free', 'name' => 'Free', 'price_amount' => 0, 'sort_order' => 0, 'segment' => 'sell',
            'description' => 'A basic public listing to get discovered.',
            'features' => ['max_gallery' => 3, 'verified_badge' => false, 'leads_receive' => false, 'featured' => false],
        ],
        [
            'slug' => 'professional', 'name' => 'Professional', 'price_amount' => 50000, 'sort_order' => 1, 'segment' => 'sell',
            'description' => 'A verified profile with RFQ leads and a larger gallery.',
            'features' => ['max_gallery' => 10, 'verified_badge' => true, 'leads_receive' => true, 'featured' => false],
        ],
        [
            'slug' => 'enterprise', 'name' => 'Enterprise', 'price_amount' => 250000, 'sort_order' => 2, 'segment' => 'sell',
            'description' => 'Everything in Professional plus featured placement and priority support.',
            'features' => ['max_gallery' => 30, 'verified_badge' => true, 'leads_receive' => true, 'featured' => true, 'api' => true],
        ],
    ];

    /**
     * Buyer-segment plans (docs/PRICING_SPEC.md's "Buy timber" table), moved
     * verbatim from the old hardcoded PricingController array into real
     * Plan rows. Nothing enforces these feature-gate keys yet — they exist
     * to establish the data model, not new entitlement wiring.
     */
    public const BUY_PLANS = [
        [
            'slug' => 'buyer-free', 'name' => 'Buyer Free', 'price_amount' => 0, 'sort_order' => 0, 'segment' => 'buy',
            'billing_period' => 'monthly',
            'description' => 'Individuals and occasional buyers.',
            'features' => ['unlimited_rfqs' => false, 'rfqs_per_month' => 3, 'saved_searches' => 2, 'max_users' => 1, 'quote_comparison' => 'basic'],
        ],
        [
            'slug' => 'buyer-plus', 'name' => 'Buyer Plus', 'price_amount' => 5000, 'sort_order' => 1, 'segment' => 'buy',
            'billing_period' => 'monthly',
            'description' => 'Frequent local buyers.',
            'features' => ['unlimited_rfqs' => true, 'saved_searches' => 10, 'max_users' => 1, 'quote_comparison' => 'advanced', 'availability_alerts' => true],
        ],
        [
            'slug' => 'business-buyer', 'name' => 'Business Buyer', 'price_amount' => 15000, 'sort_order' => 2, 'segment' => 'buy',
            'billing_period' => 'monthly',
            'description' => 'Contractors, furniture businesses, developers.',
            'features' => ['unlimited_rfqs' => true, 'max_users' => 5, 'purchase_orders' => true, 'approval_workflow' => true, 'supplier_performance_tracking' => true],
        ],
        [
            'slug' => 'corporate-buyer', 'name' => 'Corporate Buyer', 'price_amount' => 50000, 'sort_order' => 3, 'segment' => 'buy',
            'billing_period' => 'monthly',
            'description' => 'Large procurement teams.',
            'features' => ['unlimited_rfqs' => true, 'max_users' => 25, 'approval_workflow' => 'advanced', 'spend_analytics' => true, 'api' => true],
        ],
    ];

    public function run(): void
    {
        foreach ([...self::PLANS, ...self::BUY_PLANS] as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], array_merge([
                'price_currency' => 'XAF',
                'billing_period' => 'yearly',
                'is_active' => true,
            ], $plan));
        }
    }
}
