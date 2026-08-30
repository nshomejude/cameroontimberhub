<?php

namespace App\Http\Controllers\Public;

use App\Contracts\PaymentGatewayContract;
use App\Enums\PaymentProvider;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

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
        ]);

        $company = $request->user()?->companies()->first();

        abort_if(! $company, 403, 'A company account is required to purchase a plan.');

        $provider = PaymentProvider::from($data['provider']);

        $payment = Payment::create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'provider' => $provider,
            'amount' => $plan->price_amount,
            'currency' => $plan->price_currency,
        ]);

        return $this->gatewayFor($provider)->initiate($payment);
    }

    private function gatewayFor(PaymentProvider $provider): PaymentGatewayContract
    {
        $class = config("payments.providers.{$provider->value}");

        abort_unless($class && class_exists($class), 500, "No payment gateway implementation registered for {$provider->value}.");

        return app($class);
    }
}
