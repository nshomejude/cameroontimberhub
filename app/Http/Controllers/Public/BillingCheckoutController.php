<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Receipt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Self-serve subscribe flow (billing engine M11):
 *
 *   /pricing  →  GET /billing/checkout/{plan}   (this::show — method picker)
 *             →  POST /payments/checkout/{plan}  (PaymentCheckoutController::start)
 *             →  gateway
 *                 · redirect model (PayPal / Orange) → the gateway's own pages
 *                 · poll model (MTN MoMo) → GET /billing/checkout/{payment}/pending
 *                       which polls GET /billing/checkout/{payment}/status (JSON)
 *             →  GET /billing/checkout/{payment}/success
 *
 * The subscription itself is activated off the PaymentCompleted outbox event
 * (ActivateSubscriptionOnPaymentCompleted), never here — this controller only
 * reflects state.
 */
class BillingCheckoutController extends Controller
{
    public function show(Request $request, Plan $plan): View
    {
        abort_unless($plan->is_active, 404);
        abort_unless($plan->isSelfServe(), 404);

        $company = $request->user()?->companies()->first();

        if ($company === null) {
            return view('public.billing.needs-company', ['plan' => $plan]);
        }

        $providers = collect($plan->checkoutProviders())
            ->map(fn ($provider) => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'configured' => app(config("payments.providers.{$provider->value}"))->isConfigured(),
                'needs_msisdn' => $provider === \App\Enums\PaymentProvider::MtnMomo,
            ])
            ->values();

        // Billing engine M5: subtotal / tax / total breakdown for this plan
        // in the buyer's tax jurisdiction. Ships as a pure display concern —
        // when no active tax_rules row matches (the launch default) the
        // breakdown carries a null rule_id and the view shows only the total.
        $breakdown = app(\App\Services\Tax\TaxCalculator::class)->breakdown(
            $plan->price_amount,
            $plan->price_currency,
            $company->country_code ?: 'CM',
            $plan->segment,
        );

        return view('public.billing.checkout', [
            'plan' => $plan,
            'company' => $company,
            'providers' => $providers,
            'anyConfigured' => $providers->contains('configured', true),
            'breakdown' => $breakdown,
        ]);
    }

    public function pending(Request $request, Payment $payment): View
    {
        $this->authorizePayment($request, $payment);

        return view('public.billing.pending', ['payment' => $payment]);
    }

    public function status(Request $request, Payment $payment): JsonResponse
    {
        $this->authorizePayment($request, $payment);

        return response()->json([
            'status' => $payment->status->value,
            'success_url' => route('billing.checkout.success', $payment),
        ]);
    }

    public function success(Request $request, Payment $payment): View
    {
        $this->authorizePayment($request, $payment);

        if ($payment->status->value !== 'completed') {
            return view('public.billing.pending', ['payment' => $payment]);
        }

        $company = $payment->company;
        $subscription = $company?->currentSubscription;
        $receipt = Receipt::where('payment_id', $payment->id)->whereNull('voided_at')->first();

        return view('public.billing.success', [
            'payment' => $payment,
            'plan' => $payment->plan,
            'subscription' => $subscription,
            'receipt' => $receipt,
        ]);
    }

    public function overview(Request $request): View
    {
        $company = $request->user()?->companies()->first();

        $receipts = $company
            ? Receipt::whereHas('payment', fn ($q) => $q->where('company_id', $company->id))
                ->orderByDesc('issued_at')->limit(25)->get()
            : collect();

        return view('public.billing.overview', [
            'company' => $company,
            'plan' => $company?->effectivePlan(),
            'subscription' => $company?->currentSubscription,
            'receipts' => $receipts,
        ]);
    }

    private function authorizePayment(Request $request, Payment $payment): void
    {
        $companyIds = $request->user()?->companies()->pluck('companies.id')->all() ?? [];

        abort_unless(in_array($payment->company_id, $companyIds, true), 403);
    }
}
