<?php

namespace App\Http\Controllers\Public;

use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Receipt;
use App\Services\Tax\TaxCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
                'needs_msisdn' => $provider === PaymentProvider::MtnMomo,
            ])
            ->values();

        // Billing engine M5: subtotal / tax / total breakdown for this plan
        // in the buyer's tax jurisdiction. Ships as a pure display concern —
        // when no active tax_rules row matches (the launch default) the
        // breakdown carries a null rule_id and the view shows only the total.
        $breakdown = app(TaxCalculator::class)->breakdown(
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
            'canStartTrial' => $plan->hasTrial() && $this->trialEligible($company),
        ]);
    }

    /**
     * Opt-in free trial (billing engine M6, §7.5) — no payment method chosen,
     * no pre-authorisation. Redirects back to the checkout picker with an
     * error when the plan has no trial or the company already used its one
     * lifetime trial / is already on a paid plan.
     */
    public function startTrial(Request $request, Plan $plan): RedirectResponse
    {
        abort_unless($plan->is_active, 404);

        $company = $request->user()?->companies()->first();

        if ($company === null) {
            return redirect()->route('billing.checkout', $plan);
        }

        try {
            app(\App\Services\SubscriptionService::class)->startTrial($company, $plan, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['trial' => $e->getMessage()]);
        }

        return redirect()->route('billing.overview')
            ->with('status', __('messages.billing.trial_started', ['plan' => $plan->name, 'days' => $plan->trial_days]));
    }

    /** Company not already on an entitled paid subscription and hasn't used its one lifetime trial. */
    private function trialEligible(\App\Models\Company $company): bool
    {
        if ($company->subscriptions()->whereNotNull('trial_ends_at')->exists()) {
            return false;
        }

        $current = $company->currentSubscription;

        return $current === null || ! $current->entitled() || ($current->plan?->isFree() ?? true);
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

    /**
     * The customer-facing "Billing & plan" overview (billing engine Phase 2):
     * current plan + status, the company's invoices, receipts, payment history
     * and a note on the pull-model payment method. Everything is scoped to the
     * user's company — the primary one when they belong to several — and a user
     * with no company sees the "no company subscription" state.
     */
    public function overview(Request $request): View
    {
        $company = $request->user()
            ?->companies()
            ->orderByDesc('company_user.is_primary')
            ->orderBy('company_user.created_at')
            ->first();

        $cap = 24;

        $invoices = $company
            ? Invoice::where('company_id', $company->id)->orderByDesc('issued_at')->orderByDesc('id')->limit($cap)->get()
            : collect();

        $receipts = $company
            ? Receipt::whereHas('payment', fn ($q) => $q->where('company_id', $company->id))
                ->orderByDesc('issued_at')->orderByDesc('id')->limit($cap)->get()
            : collect();

        $payments = $company
            ? Payment::where('company_id', $company->id)->orderByDesc('created_at')->orderByDesc('id')->limit($cap)->get()
            : collect();

        return view('public.billing.overview', [
            'company' => $company,
            'plan' => $company?->effectivePlan(),
            'subscription' => $company?->currentSubscription,
            'invoices' => $invoices,
            'receipts' => $receipts,
            'payments' => $payments,
            'cap' => $cap,
        ]);
    }

    private function authorizePayment(Request $request, Payment $payment): void
    {
        $companyIds = $request->user()?->companies()->pluck('companies.id')->all() ?? [];

        abort_unless(in_array($payment->company_id, $companyIds, true), 403);
    }
}
