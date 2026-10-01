<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Payments\ProviderFees;
use App\Services\Tax\TaxCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/billing/checkout/{plan}` — the price breakdown the app must
 * show BEFORE the user authorises a plan payment (pricing spec §19/§20:
 * subtotal, tax, fees and total separately; a provider fee passed through to
 * the payer disclosed before authorisation).
 *
 * Read-only: the payment itself still starts on the web checkout
 * (`checkout_url`, POST /payments/checkout/{plan}), which prices the charge
 * through the exact same TaxCalculator + ProviderFees::breakdown() calls, so
 * the `total` returned here per provider is what that provider is charged.
 */
class BillingCheckoutController extends Controller
{
    public function show(Request $request, Plan $plan): JsonResponse
    {
        abort_unless($plan->is_active && $plan->isSelfServe(), 404);

        $company = $request->user()?->companies()->first();
        $currency = strtoupper((string) $plan->price_currency);

        $tax = app(TaxCalculator::class)->breakdown(
            $plan->price_amount,
            $plan->price_currency,
            $company?->country_code ?: 'CM',
            $plan->segment,
        );
        $base = (string) ($tax['total'] ?? $plan->price_amount);

        $providers = collect($plan->checkoutProviders())->map(function ($provider) use ($base, $currency): array {
            $fee = ProviderFees::breakdown($provider, $base, $currency);

            return [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'configured' => (bool) app(config("payments.providers.{$provider->value}"))->isConfigured(),
                'subtotal' => $fee['base'],
                'provider_fee' => $fee['fee'],
                'provider_fee_bearer' => $fee['bearer'],
                'provider_fee_passed_through' => $fee['passed_through'],
                'provider_fee_percent' => $fee['percent'],
                'provider_fee_fixed' => $fee['fixed'],
                'total' => $fee['total'],
            ];
        })->values();

        return response()->json(['data' => [
            'plan' => $plan->slug,
            'plan_name' => $plan->name,
            'billing_period' => $plan->billing_period,
            'currency' => $currency,
            'price' => bcadd((string) $plan->price_amount, '0', 2),
            'tax' => $tax['rule_id'] !== null ? [
                'label' => $tax['tax_label'],
                'rate' => (string) $tax['tax_rate'],
                'amount' => bcadd((string) $tax['tax_amount'], '0', 2),
            ] : null,
            'providers' => $providers,
            'checkout_url' => route('billing.checkout', $plan),
        ]]);
    }
}
