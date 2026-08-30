<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\View\View;

/**
 * The public /pricing page — a segmented, informational catalogue covering
 * the 8 customer entry points defined in docs/PRICING_SPEC.md §24 (Buy
 * timber / Sell timber locally / Deal timber / Export timber / Buy
 * internationally / Verify & comply / Analyze the market / Learn).
 *
 * Scope, stated plainly (see docs/GAP_PLAN.md item 0.9 vs 0.9b): this is
 * DISPLAY ONLY. Two segments are genuinely backed by live, admin-manageable
 * data in the `plans` table (each row now carries a `segment` column —
 * `Plan::forSegment('sell')` / `Plan::forSegment('buy')` — editable via
 * `app/Filament/Resources/Plans/`): the domestic supplier tier ("Sell
 * timber locally", already driving real `companies.plan_id` assignment)
 * and the domestic buyer tier ("Buy timber"). Those cards render from the
 * `Plan` model exactly as before. Every other segment below is still
 * static catalogue content matching docs/PRICING_SPEC.md's published
 * launch prices; none of it is wired to checkout, billing, subscriptions,
 * entitlement enforcement, tax calculation, or invoicing yet — every CTA
 * in those sections points at registration or contact, never a live
 * payment flow, so the page never claims to sell something that doesn't
 * yet exist to be sold. Migrating the remaining 6 segments into the same
 * DB-backed pattern, and building real checkout, is tracked separately as
 * 0.9b, blocked on a real payment-provider decision.
 */
class PricingController extends Controller
{
    public function index(): View
    {
        return view('public.pricing', [
            'supplierPlans' => Plan::active()->forSegment('sell')->get(),
            'buyerPlans' => Plan::active()->forSegment('buy')->get(),
            'segments' => $this->segments(),
        ]);
    }

    /**
     * Static catalogue content for every segment except domestic supplier
     * (which comes from the live `Plan` model above). Prices are taken
     * verbatim from docs/PRICING_SPEC.md's Implementation-Ready Pricing
     * Catalogue (§26) and per-segment tables (§4–§17).
     *
     * @return array<string, array{
     *     id: string, label: string, eyebrow: string, intro: string,
     *     plans: list<array{name: string, price: string, period: string, description: string, features: list<string>, highlight?: bool}>
     * }>
     */
    private function segments(): array
    {
        return [
            'buy' => [
                'id' => 'buy',
                'label' => 'Buy timber',
                'eyebrow' => 'Domestic buyers',
                'intro' => 'For individuals, contractors, and procurement teams sourcing timber inside Cameroon. Prices in XAF.',
                'plans' => [], // rendered live from $buyerPlans in the view
            ],
            'sell' => [
                'id' => 'sell',
                'label' => 'Sell timber locally',
                'eyebrow' => 'Domestic suppliers',
                'intro' => 'For sawmills, processors, and timber merchants listing and quoting inside Cameroon. Prices in XAF.',
                'plans' => [], // rendered live from $supplierPlans in the view
            ],
            'deal' => [
                'id' => 'deal',
                'label' => 'Deal timber',
                'eyebrow' => 'Dealers & yard operators',
                'intro' => 'For resellers and operators managing stocked timber yards. Prices in XAF.',
                'plans' => [
                    ['name' => 'Dealer Free', 'price' => '0 XAF', 'period' => 'forever', 'description' => 'Get started with a basic yard profile.', 'features' => ['Profile', 'Up to 20 stock items', '3 RFQs / month']],
                    ['name' => 'Dealer Pro', 'price' => '10,000 XAF', 'period' => 'month (100,000/yr)', 'description' => 'Unlimited listings and orders.', 'features' => ['Unlimited listings & stock', 'Prices, quotes, orders', '5 users'], 'highlight' => true],
                    ['name' => 'Dealer Network', 'price' => '30,000 XAF', 'period' => 'month (300,000/yr)', 'description' => 'Multiple yards under one account.', 'features' => ['Multiple yards, shared stock', 'Network analytics', '15 users']],
                ],
            ],
            'export' => [
                'id' => 'export',
                'label' => 'Export timber',
                'eyebrow' => 'Exporters & industrial suppliers',
                'intro' => 'For licensed export-oriented processors and suppliers reaching international buyers. Prices in USD.',
                'plans' => [
                    ['name' => 'Exporter Professional', 'price' => '$29', 'period' => 'month ($290/yr)', 'description' => 'Enhanced exporter profile.', 'features' => ['100 products', 'Unlimited RFQ responses', 'Export-market visibility']],
                    ['name' => 'Exporter Business', 'price' => '$79', 'period' => 'month ($790/yr)', 'description' => 'Full export operations.', 'features' => ['Unlimited products', 'Premium placement', 'Inventory, compliance & traceability profile', '15 users'], 'highlight' => true],
                    ['name' => 'Exporter Enterprise', 'price' => 'From $249', 'period' => 'month (from $2,490/yr)', 'description' => 'Multi-site exporters.', 'features' => ['Multi-site', 'API access', 'Custom workflows', 'Dedicated support']],
                ],
            ],
            'buy-international' => [
                'id' => 'buy-international',
                'label' => 'Buy internationally',
                'eyebrow' => 'International buyers',
                'intro' => 'For importers, distributors, manufacturers, and wholesalers sourcing from Cameroon. Prices in USD.',
                'plans' => [
                    ['name' => 'International Buyer Free', 'price' => '$0', 'period' => 'forever', 'description' => 'Explore verified suppliers.', 'features' => ['Supplier discovery', '3 RFQs / month', 'Basic comparison']],
                    ['name' => 'Buyer Professional', 'price' => '$39', 'period' => 'month ($390/yr)', 'description' => 'Serious sourcing at scale.', 'features' => ['Unlimited RFQs', 'Advanced sourcing tools', 'Compliance checklist', '5 users'], 'highlight' => true],
                    ['name' => 'Buyer Enterprise', 'price' => 'From $199', 'period' => 'month (from $1,990/yr)', 'description' => 'Large procurement organizations.', 'features' => ['Unlimited users', 'Procurement workflows', 'Analytics', 'API + account support']],
                ],
            ],
            'verify-comply' => [
                'id' => 'verify-comply',
                'label' => 'Verify & comply',
                'eyebrow' => 'Verification, compliance & traceability',
                'intro' => 'Evidence-based verification and workspace tools for compliance and provenance. Verification fees purchase a review process — approval is not guaranteed and depends on the evidence provided.',
                'plans' => [
                    ['name' => 'Verified Timber Supplier', 'price' => '25,000 XAF / $75', 'period' => '12 months', 'description' => 'Business + operating/product evidence review.', 'features' => ['Identity & registration checks', 'Product evidence review', '12-month validity']],
                    ['name' => 'Verified Exporter', 'price' => '100,000 XAF / $150', 'period' => '12 months', 'description' => 'Exporter capability + export documentation review.', 'features' => ['Everything in Verified Supplier', 'Export documentation checks', '12-month validity'], 'highlight' => true],
                    ['name' => 'Compliance Professional', 'price' => '30,000 XAF', 'period' => 'month', 'description' => 'EUDR workspace for exporters and enterprises.', 'features' => ['Evidence collection', 'Risk assessment', 'Audit trail & reporting']],
                    ['name' => 'Traceability Professional', 'price' => '75,000 XAF', 'period' => 'month', 'description' => 'Multi-lot chain-of-custody tracking.', 'features' => ['Source & geolocation records', 'Processing events', 'Buyer-facing provenance page']],
                ],
            ],
            'analyze' => [
                'id' => 'analyze',
                'label' => 'Analyze the market',
                'eyebrow' => 'Market intelligence & data',
                'intro' => 'Price intelligence, species and trade trend data for professionals and institutions. Prices in USD.',
                'plans' => [
                    ['name' => 'Market Data Free', 'price' => '$0', 'period' => 'forever', 'description' => 'Selected public market statistics.', 'features' => ['Public price snapshots', 'Public trend summaries']],
                    ['name' => 'Market Intelligence Professional', 'price' => '$99', 'period' => 'month ($990/yr)', 'description' => 'Price intelligence and downloadable reports.', 'features' => ['Species & trade trends', 'Price bands', 'Downloadable reports'], 'highlight' => true],
                    ['name' => 'Market Intelligence Enterprise', 'price' => 'From $399', 'period' => 'month (from $3,990/yr)', 'description' => 'Full datasets and custom analysis.', 'features' => ['Full historical datasets', 'Custom analysis', 'API access']],
                ],
            ],
            'learn' => [
                'id' => 'learn',
                'label' => 'Learn',
                'eyebrow' => 'Education',
                'intro' => 'Core knowledge stays free — species education, legal resources, and guides are open to everyone. Paid courses go deeper.',
                'plans' => [
                    ['name' => 'Knowledge Centre', 'price' => '0 XAF / $0', 'period' => 'always free', 'description' => 'Public educational resources.', 'features' => ['Species education', 'Legal & regulatory guides', 'Glossary and calculators']],
                    ['name' => 'Short course', 'price' => '25,000–50,000 XAF', 'period' => 'per course ($29–$59)', 'description' => 'Self-paced professional course.', 'features' => ['Self-paced format', 'Practical timber-trade topics']],
                    ['name' => 'Professional certificate', 'price' => '50,000 XAF', 'period' => 'per assessment ($99)', 'description' => 'CT Hub certificate of assessment.', 'features' => ['Assessment & certificate', 'Advanced curriculum access']],
                ],
            ],
        ];
    }
}
