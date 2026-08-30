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
 * Scope, stated plainly (see docs/GAP_PLAN.md item 0.9 / 0.9b): all 8
 * segments are now backed by live, admin-manageable data in the `plans`
 * table (each row carries a `segment` column — `Plan::forSegment($id)` —
 * editable via `app/Filament/Resources/Plans/`). This remains DISPLAY
 * ONLY: none of it is wired to checkout, billing, subscriptions,
 * entitlement enforcement, tax calculation, or invoicing yet. Only the
 * domestic supplier ("sell") and domestic buyer ("buy") segments drive a
 * real feature-gate boolean set (`verified_badge`, `leads_receive`,
 * `featured`, `api`, `max_gallery`) enforced elsewhere in the app (e.g.
 * `companies.plan_id`); the other 6 segments' `Plan` rows carry their
 * feature bullets as a flat `{"bullets": [...]}"` list with no gates
 * behind them yet. Every CTA in those sections points at registration or
 * contact, never a live payment flow, so the page never claims to sell
 * something that doesn't yet exist to be sold. Building real checkout and
 * wiring the remaining 6 segments' plans to entitlements is tracked
 * separately as 0.9b, blocked on a real payment-provider decision.
 */
class PricingController extends Controller
{
    public function index(): View
    {
        return view('public.pricing', [
            'supplierPlans' => Plan::active()->forSegment('sell')->get(),
            'buyerPlans' => Plan::active()->forSegment('buy')->get(),
            'dealerPlans' => Plan::active()->forSegment('deal')->get(),
            'exporterPlans' => Plan::active()->forSegment('export')->get(),
            'buyInternationalPlans' => Plan::active()->forSegment('buy-international')->get(),
            'verifyComplyPlans' => Plan::active()->forSegment('verify-comply')->get(),
            'analyzePlans' => Plan::active()->forSegment('analyze')->get(),
            'learnPlans' => Plan::active()->forSegment('learn')->get(),
            'segments' => $this->segments(),
        ]);
    }

    /**
     * Segment metadata (id/label/eyebrow/intro) for all 8 /pricing
     * sections. Every segment's plans now come from the live `Plan` model
     * (passed to the view above, keyed per segment) — none of these
     * arrays carry hardcoded plan content any longer.
     *
     * @return array<string, array{id: string, label: string, eyebrow: string, intro: string, plans: array{}}>
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
                'plans' => [], // rendered live from $dealerPlans in the view
            ],
            'export' => [
                'id' => 'export',
                'label' => 'Export timber',
                'eyebrow' => 'Exporters & industrial suppliers',
                'intro' => 'For licensed export-oriented processors and suppliers reaching international buyers. Prices in USD.',
                'plans' => [], // rendered live from $exporterPlans in the view
            ],
            'buy-international' => [
                'id' => 'buy-international',
                'label' => 'Buy internationally',
                'eyebrow' => 'International buyers',
                'intro' => 'For importers, distributors, manufacturers, and wholesalers sourcing from Cameroon. Prices in USD.',
                'plans' => [], // rendered live from $buyInternationalPlans in the view
            ],
            'verify-comply' => [
                'id' => 'verify-comply',
                'label' => 'Verify & comply',
                'eyebrow' => 'Verification, compliance & traceability',
                'intro' => 'Evidence-based verification and workspace tools for compliance and provenance. Verification fees purchase a review process — approval is not guaranteed and depends on the evidence provided.',
                'plans' => [], // rendered live from $verifyComplyPlans in the view
            ],
            'analyze' => [
                'id' => 'analyze',
                'label' => 'Analyze the market',
                'eyebrow' => 'Market intelligence & data',
                'intro' => 'Price intelligence, species and trade trend data for professionals and institutions. Prices in USD.',
                'plans' => [], // rendered live from $analyzePlans in the view
            ],
            'learn' => [
                'id' => 'learn',
                'label' => 'Learn',
                'eyebrow' => 'Education',
                'intro' => 'Core knowledge stays free — species education, legal resources, and guides are open to everyone. Paid courses go deeper.',
                'plans' => [], // rendered live from $learnPlans in the view
            ],
        ];
    }
}
