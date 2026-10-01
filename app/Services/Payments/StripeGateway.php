<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayContract;
use App\Domain\Commerce\Commands\RecordPaymentCompletionCommand;
use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Support\Bus\CommandBus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe integration using Stripe's hosted Checkout Session flow. Stripe
 * hosts the actual card-entry page; this class never touches card data
 * directly. See app/Contracts/PaymentGatewayContract.php for the contract
 * and routes/payments/stripe.php for the success/cancel/webhook routes.
 *
 * Chosen as the ONE gateway wired through App\Domain\Commerce\Commands\
 * RecordPaymentCompletionCommand (architecture plan Phase 4, Commerce &
 * Billing) as the proof-of-pattern for this batch: its webhook handler has
 * the simplest, most linear "provider confirms -> mark completed" shape of
 * the four gateways (MtnMomoGateway, OrangeMoneyGateway and PayPalGateway
 * each have multiple markCompleted() call sites across different
 * polling/callback code paths), making it the lowest-risk place to prove the
 * seam without touching business logic. The other three gateways'
 * markCompleted() call sites are left as-is for now — rewiring all four in
 * one pass was judged too invasive for this task's scope; each is a
 * mechanical follow-up (swap `$payment->markCompleted(...)` for
 * `app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand(...))`)
 * once this pattern is proven in production.
 */
class StripeGateway implements PaymentGatewayContract
{
    public function isConfigured(): bool
    {
        return GatewayCredentials::isConfigured(PaymentProvider::Stripe);
    }

    public function initiate(Payment $payment): RedirectResponse|Response
    {
        if (! $this->isConfigured()) {
            return response()->view('payments.not-configured', ['provider' => 'Stripe'], 503);
        }

        $cfg = GatewayCredentials::for(PaymentProvider::Stripe);
        $client = new StripeClient($cfg['secret_key'] ?? null);

        $currency = strtolower($payment->currency ?? ($cfg['currency'] ?? 'usd'));

        try {
            $session = $client->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $payment->plan?->name ?? 'Plan subscription',
                        ],
                        // Smallest currency unit: cents for USD, but XAF is a
                        // Stripe zero-decimal currency — francs, never x100.
                        'unit_amount' => PaymentAmount::minorUnits($payment),
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => route('payments.stripe.success', ['payment' => $payment->id]).'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => URL::temporarySignedRoute('payments.stripe.cancel', now()->addDay(), ['payment' => $payment->id]),
                'metadata' => [
                    'payment_id' => (string) $payment->id,
                ],
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Stripe checkout session creation failed', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);

            $payment->markFailed();

            return response()->view('payments.not-configured', [
                'provider' => 'Stripe',
            ], 503);
        }

        $payment->update(['provider_reference' => $session->id]);

        return redirect()->away($session->url);
    }

    public function handleWebhook(Request $request): Response
    {
        $webhookSecret = GatewayCredentials::for(PaymentProvider::Stripe)['webhook_secret'] ?? null;

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
                $webhookSecret
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            return response('Invalid signature', 400);
        }

        $type = $event->type;
        $object = $event->data->object ?? null;

        // `checkout.session.completed` only means the customer finished the
        // Checkout page: for delayed methods `payment_status` is still
        // `unpaid` and the money arrives (or not) later via
        // `checkout.session.async_payment_succeeded|failed`.
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && $object) {
            $payment = $this->findPayment($object);

            if ($payment && $this->sessionPaysFor($payment, $object)) {
                app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand(
                    paymentId: $payment->getKey(),
                    providerReference: $object->payment_intent ?? null,
                ));
            }
        }

        if (in_array($type, ['checkout.session.expired', 'checkout.session.async_payment_failed', 'payment_intent.payment_failed'], true) && $object) {
            $payment = $this->findPayment($object);

            $payment?->markFailed();
        }

        return response('OK', 200);
    }

    /**
     * A Checkout Session settles a Payment only once Stripe reports it paid,
     * for exactly our amount (in the currency's smallest unit) and currency.
     */
    private function sessionPaysFor(Payment $payment, object $session): bool
    {
        $paid = ($session->payment_status ?? null) === 'paid';
        $amountOk = isset($session->amount_total) && (int) $session->amount_total === PaymentAmount::minorUnits($payment);
        $currencyOk = isset($session->currency) && strtolower((string) $session->currency) === strtolower((string) $payment->currency);

        if ($paid && $amountOk && $currencyOk) {
            return true;
        }

        Log::log($paid ? 'critical' : 'info', 'Stripe session does not (yet) pay for this payment — not completing', [
            'payment_id' => $payment->id,
            'payment_status' => $session->payment_status ?? null,
            'expected_amount' => PaymentAmount::minorUnits($payment),
            'amount_total' => $session->amount_total ?? null,
            'expected_currency' => $payment->currency,
            'currency' => $session->currency ?? null,
        ]);

        return false;
    }

    private function findPayment(object $object): ?Payment
    {
        $paymentId = $object->metadata->payment_id ?? null;

        if ($paymentId) {
            $payment = Payment::find($paymentId);

            if ($payment) {
                return $payment;
            }
        }

        if (isset($object->id)) {
            return Payment::where('provider_reference', $object->id)->first();
        }

        return null;
    }
}
