<?php

namespace App\Http\Controllers\Public;

use App\Contracts\PaymentGatewayContract;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use App\Services\Payments\ProviderFees;
use App\Services\Tax\TaxCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared checkout entry point for every payment gateway. Creates a
 * pending Payment row scoped to the authenticated user's company and the
 * chosen Plan, then delegates to that provider's PaymentGatewayContract
 * implementation (resolved from config('payments.providers')) to actually
 * start the payment. Each provider's webhook is handled separately — see
 * routes/payments/{provider}.php — since callback shapes differ per
 * gateway and none of them come back through this controller.
 *
 * This controller never touches real money itself; it only orchestrates.
 * A provider whose isConfigured() returns false refuses at the gateway
 * layer, not here — this controller doesn't need to know which providers
 * are live versus placeholder.
 */
class PaymentCheckoutController extends Controller
{
    public function start(Request $request, Plan $plan): RedirectResponse|Response
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_column(PaymentProvider::cases(), 'value'))],
            // E.164 or a local Cameroon 6XXXXXXXX number — only used (and,
            // when supplied, always used) for the MTN MoMo push flow.
            'msisdn' => ['nullable', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
        ]);

        // A retired plan is not for sale (the picker never offers one; this
        // closes a hand-posted plan id).
        abort_unless($plan->is_active, 404);

        $company = $request->user()?->companies()->first();

        abort_if(! $company, 403, 'A company account is required to purchase a plan.');

        $provider = PaymentProvider::from($data['provider']);

        // Mobile money settles in XAF only, and both gateways send our
        // decimal `amount` with the gateway's configured currency. A plan
        // priced in another currency (e.g. a USD enterprise plan, which the
        // self-serve allow-list below does not cover) would otherwise be
        // charged as that many FRANCS — USD 249 paid as XAF 249.
        $mobileMoney = [PaymentProvider::MtnMomo, PaymentProvider::OrangeMoney];
        if (in_array($provider, $mobileMoney, true) && strtoupper((string) $plan->price_currency) !== 'XAF') {
            throw ValidationException::withMessages([
                'provider' => __('messages.billing.provider_not_allowed', ['currency' => $plan->price_currency]),
            ]);
        }

        // Billing engine M11: the provider must be one this plan's currency
        // can actually settle on (XAF → MoMo/Orange, USD → PayPal). Rejects
        // e.g. paying a USD exporter plan with MTN Mobile Money.
        $allowed = $plan->checkoutProviders();
        if ($plan->isSelfServe() && $allowed !== [] && ! in_array($provider, $allowed, true)) {
            throw ValidationException::withMessages([
                'provider' => __('messages.billing.provider_not_allowed', ['currency' => $plan->price_currency]),
            ]);
        }

        // Billing engine M5: apply tax ONLY when an active tax_rules row
        // matches the buyer's jurisdiction/segment. At launch the Cameroon
        // TVA rule ships inactive, so this resolves to no rule, `amount`
        // stays exactly `$plan->price_amount` and no `tax` metadata is
        // written — existing behaviour and tests are untouched. When finance
        // activates the rule, `amount` becomes subtotal+tax and the full
        // breakdown is persisted to `metadata['tax']` for the receipt/M4.
        $breakdown = app(TaxCalculator::class)->breakdown(
            $plan->price_amount,
            $plan->price_currency,
            $company->country_code ?: 'CM',
            $plan->segment,
        );
        $taxable = $breakdown['rule_id'] !== null;

        // Provider processing fee (PayPal commission structure, part A):
        // `base_amount` is what the platform is owed (price incl. any tax);
        // when the provider's fee is passed through to the buyer, `amount`
        // is grossed up so the platform still nets the base — the same
        // subtotal / fee / total the checkout page disclosed before the
        // payer chose to proceed. `amount` stays exactly what the payer is
        // charged and what the gateway sends to (and verifies against) the
        // provider.
        $fee = ProviderFees::breakdown(
            $provider,
            $taxable ? (string) $breakdown['total'] : (string) $plan->price_amount,
            (string) $plan->price_currency,
        );

        $payment = Payment::create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'provider' => $provider,
            'amount' => $fee['total'],
            'base_amount' => $fee['base'],
            'provider_fee_amount' => $fee['fee'],
            'provider_fee_bearer' => $fee['bearer'],
            'currency' => $plan->price_currency,
            'metadata' => array_filter([
                'msisdn' => $data['msisdn'] ?? null,
                'tax' => $taxable ? $breakdown : null,
            ]),
        ]);

        // MTN MoMo is a poll model: fire the push here and hand the browser a
        // waiting page that polls for the webhook, rather than a hosted
        // redirect. Only when the picker supplied a phone number — without
        // one we fall back to the gateway's own phone-number form.
        if ($provider === PaymentProvider::MtnMomo && filled($data['msisdn'] ?? null)) {
            $gateway = $this->gatewayFor($provider);

            if (! $gateway->isConfigured()) {
                return $gateway->initiate($payment);
            }

            $result = $gateway->requestToPay($payment, $data['msisdn']);

            return $result === 'pending'
                ? redirect()->route('billing.checkout.pending', $payment)
                : redirect()->route('billing.checkout', $plan)->withErrors([
                    'provider' => __('messages.billing.momo_push_failed'),
                ]);
        }

        return $this->gatewayFor($provider)->initiate($payment);
    }

    private function gatewayFor(PaymentProvider $provider): PaymentGatewayContract
    {
        $class = config("payments.providers.{$provider->value}");

        abort_unless($class && class_exists($class), 500, "No payment gateway implementation registered for {$provider->value}.");

        return app($class);
    }
}
