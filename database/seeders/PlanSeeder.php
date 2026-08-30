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

    /** Dealer-segment plans ("Deal timber"), moved verbatim from PricingController. */
    public const DEAL_PLANS = [
        [
            'slug' => 'dealer-free', 'name' => 'Dealer Free', 'price_amount' => 0, 'sort_order' => 0, 'segment' => 'deal',
            'price_currency' => 'XAF', 'billing_period' => 'yearly',
            'description' => 'Get started with a basic yard profile.',
            'features' => ['bullets' => ['Profile', 'Up to 20 stock items', '3 RFQs / month']],
        ],
        [
            'slug' => 'dealer-pro', 'name' => 'Dealer Pro', 'price_amount' => 10000, 'sort_order' => 1, 'segment' => 'deal',
            'price_currency' => 'XAF', 'billing_period' => 'monthly',
            'description' => 'Unlimited listings and orders.',
            'features' => ['bullets' => ['Unlimited listings & stock', 'Prices, quotes, orders', '5 users']],
        ],
        [
            'slug' => 'dealer-network', 'name' => 'Dealer Network', 'price_amount' => 30000, 'sort_order' => 2, 'segment' => 'deal',
            'price_currency' => 'XAF', 'billing_period' => 'monthly',
            'description' => 'Multiple yards under one account.',
            'features' => ['bullets' => ['Multiple yards, shared stock', 'Network analytics', '15 users']],
        ],
    ];

    /** Exporter-segment plans ("Export timber"), moved verbatim from PricingController. */
    public const EXPORT_PLANS = [
        [
            'slug' => 'exporter-professional', 'name' => 'Exporter Professional', 'price_amount' => 29, 'sort_order' => 0, 'segment' => 'export',
            'price_currency' => 'USD', 'billing_period' => 'monthly',
            'description' => 'Enhanced exporter profile.',
            'features' => ['bullets' => ['100 products', 'Unlimited RFQ responses', 'Export-market visibility']],
        ],
        [
            'slug' => 'exporter-business', 'name' => 'Exporter Business', 'price_amount' => 79, 'sort_order' => 1, 'segment' => 'export',
            'price_currency' => 'USD', 'billing_period' => 'monthly',
            'description' => 'Full export operations.',
            'features' => ['bullets' => ['Unlimited products', 'Premium placement', 'Inventory, compliance & traceability profile', '15 users']],
        ],
        [
            'slug' => 'exporter-enterprise', 'name' => 'Exporter Enterprise', 'price_amount' => 249, 'sort_order' => 2, 'segment' => 'export',
            'price_currency' => 'USD', 'billing_period' => 'monthly',
            'description' => 'Multi-site exporters.',
            'features' => ['bullets' => ['Multi-site', 'API access', 'Custom workflows', 'Dedicated support']],
        ],
    ];

    /** International-buyer-segment plans ("Buy internationally"), moved verbatim from PricingController. */
    public const BUY_INTERNATIONAL_PLANS = [
        [
            'slug' => 'international-buyer-free', 'name' => 'International Buyer Free', 'price_amount' => 0, 'sort_order' => 0, 'segment' => 'buy-international',
            'price_currency' => 'USD', 'billing_period' => 'yearly',
            'description' => 'Explore verified suppliers.',
            'features' => ['bullets' => ['Supplier discovery', '3 RFQs / month', 'Basic comparison']],
        ],
        [
            'slug' => 'buyer-professional', 'name' => 'Buyer Professional', 'price_amount' => 39, 'sort_order' => 1, 'segment' => 'buy-international',
            'price_currency' => 'USD', 'billing_period' => 'monthly',
            'description' => 'Serious sourcing at scale.',
            'features' => ['bullets' => ['Unlimited RFQs', 'Advanced sourcing tools', 'Compliance checklist', '5 users']],
        ],
        [
            'slug' => 'buyer-enterprise', 'name' => 'Buyer Enterprise', 'price_amount' => 199, 'sort_order' => 2, 'segment' => 'buy-international',
            'price_currency' => 'USD', 'billing_period' => 'monthly',
            'description' => 'Large procurement organizations.',
            'features' => ['bullets' => ['Unlimited users', 'Procurement workflows', 'Analytics', 'API + account support']],
        ],
    ];

    /** Verify & comply-segment plans, moved verbatim from PricingController. */
    public const VERIFY_COMPLY_PLANS = [
        [
            'slug' => 'verified-timber-supplier', 'name' => 'Verified Timber Supplier', 'price_amount' => 25000, 'sort_order' => 0, 'segment' => 'verify-comply',
            'price_currency' => 'XAF', 'billing_period' => 'yearly',
            'description' => 'Business + operating/product evidence review.',
            'features' => ['bullets' => ['Identity & registration checks', 'Product evidence review', '12-month validity']],
        ],
        [
            'slug' => 'verified-exporter', 'name' => 'Verified Exporter', 'price_amount' => 100000, 'sort_order' => 1, 'segment' => 'verify-comply',
            'price_currency' => 'XAF', 'billing_period' => 'yearly',
            'description' => 'Exporter capability + export documentation review.',
            'features' => ['bullets' => ['Everything in Verified Supplier', 'Export documentation checks', '12-month validity']],
        ],
        [
            'slug' => 'compliance-professional', 'name' => 'Compliance Professional', 'price_amount' => 30000, 'sort_order' => 2, 'segment' => 'verify-comply',
            'price_currency' => 'XAF', 'billing_period' => 'monthly',
            'description' => 'EUDR workspace for exporters and enterprises.',
            'features' => ['bullets' => ['Evidence collection', 'Risk assessment', 'Audit trail & reporting']],
        ],
        [
            'slug' => 'traceability-professional', 'name' => 'Traceability Professional', 'price_amount' => 75000, 'sort_order' => 3, 'segment' => 'verify-comply',
            'price_currency' => 'XAF', 'billing_period' => 'monthly',
            'description' => 'Multi-lot chain-of-custody tracking.',
            'features' => ['bullets' => ['Source & geolocation records', 'Processing events', 'Buyer-facing provenance page']],
        ],
    ];

    /** Market-intelligence-segment plans ("Analyze the market"), moved verbatim from PricingController. */
    public const ANALYZE_PLANS = [
        [
            'slug' => 'market-data-free', 'name' => 'Market Data Free', 'price_amount' => 0, 'sort_order' => 0, 'segment' => 'analyze',
            'price_currency' => 'USD', 'billing_period' => 'yearly',
            'description' => 'Selected public market statistics.',
            'features' => ['bullets' => ['Public price snapshots', 'Public trend summaries']],
        ],
        [
            'slug' => 'market-intelligence-professional', 'name' => 'Market Intelligence Professional', 'price_amount' => 99, 'sort_order' => 1, 'segment' => 'analyze',
            'price_currency' => 'USD', 'billing_period' => 'monthly',
            'description' => 'Price intelligence and downloadable reports.',
            'features' => ['bullets' => ['Species & trade trends', 'Price bands', 'Downloadable reports']],
        ],
        [
            'slug' => 'market-intelligence-enterprise', 'name' => 'Market Intelligence Enterprise', 'price_amount' => 399, 'sort_order' => 2, 'segment' => 'analyze',
            'price_currency' => 'USD', 'billing_period' => 'monthly',
            'description' => 'Full datasets and custom analysis.',
            'features' => ['bullets' => ['Full historical datasets', 'Custom analysis', 'API access']],
        ],
    ];

    /** Education-segment plans ("Learn"), moved verbatim from PricingController. */
    public const LEARN_PLANS = [
        [
            'slug' => 'knowledge-centre', 'name' => 'Knowledge Centre', 'price_amount' => 0, 'sort_order' => 0, 'segment' => 'learn',
            'price_currency' => 'XAF', 'billing_period' => 'yearly',
            'description' => 'Public educational resources.',
            'features' => ['bullets' => ['Species education', 'Legal & regulatory guides', 'Glossary and calculators']],
        ],
        [
            'slug' => 'short-course', 'name' => 'Short course', 'price_amount' => 25000, 'sort_order' => 1, 'segment' => 'learn',
            'price_currency' => 'XAF', 'billing_period' => 'once',
            'description' => 'Self-paced professional course.',
            'features' => ['bullets' => ['Self-paced format', 'Practical timber-trade topics']],
        ],
        [
            'slug' => 'professional-certificate', 'name' => 'Professional certificate', 'price_amount' => 50000, 'sort_order' => 2, 'segment' => 'learn',
            'price_currency' => 'XAF', 'billing_period' => 'once',
            'description' => 'CT Hub certificate of assessment.',
            'features' => ['bullets' => ['Assessment & certificate', 'Advanced curriculum access']],
        ],
    ];

    public function run(): void
    {
        foreach ([
            ...self::PLANS,
            ...self::BUY_PLANS,
            ...self::DEAL_PLANS,
            ...self::EXPORT_PLANS,
            ...self::BUY_INTERNATIONAL_PLANS,
            ...self::VERIFY_COMPLY_PLANS,
            ...self::ANALYZE_PLANS,
            ...self::LEARN_PLANS,
        ] as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], array_merge([
                'price_currency' => 'XAF',
                'billing_period' => 'yearly',
                'is_active' => true,
            ], $plan));
        }
    }
}
