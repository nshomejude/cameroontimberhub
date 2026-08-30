<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayContract;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
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
 */
class StripeGateway implements PaymentGatewayContract
{
    public function isConfigured(): bool
    {
        return filled(config('payments.stripe.secret_key'));
    }

    public function initiate(Payment $payment): RedirectResponse|Response
    {
        if (! $this->isConfigured()) {
            return response()->view('payments.not-configured', ['provider' => 'Stripe'], 503);
        }

        $client = new StripeClient(config('payments.stripe.secret_key'));

        $currency = strtolower($payment->currency ?? config('payments.stripe.currency'));

        try {
            $session = $client->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => $payment->plan?->name ?? 'Plan subscription',
                        ],
                        'unit_amount' => (int) round(((float) $payment->amount) * 100),
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => route('payments.stripe.success', ['payment' => $payment->id]).'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('payments.stripe.cancel', ['payment' => $payment->id]),
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
        $webhookSecret = config('payments.stripe.webhook_secret');

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

        if ($type === 'checkout.session.completed' && $object) {
            $payment = $this->findPayment($object);

            $payment?->markCompleted($object->payment_intent ?? null);
        }

        if (in_array($type, ['checkout.session.expired', 'payment_intent.payment_failed'], true) && $object) {
            $payment = $this->findPayment($object);

            $payment?->markFailed();
        }

        return response('OK', 200);
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
