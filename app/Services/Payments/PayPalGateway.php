<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayContract;
use App\Domain\Commerce\Commands\RecordPaymentCompletionCommand;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Bus\CommandBus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * PayPal gateway using the v2 Orders API (create order -> redirect to
 * approval -> capture on return leg). Raw HTTP calls via the Http facade,
 * no SDK. See routes/payments/paypal.php for the return/cancel/webhook
 * routes and resources/views/payments/paypal for the accompanying views.
 */
class PayPalGateway implements PaymentGatewayContract
{
    public function isConfigured(): bool
    {
        return GatewayCredentials::isConfigured(PaymentProvider::PayPal);
    }

    /** @return array<string, mixed> */
    private function cfg(): array
    {
        return GatewayCredentials::for(PaymentProvider::PayPal);
    }

    public function initiate(Payment $payment): RedirectResponse|Response
    {
        if (! $this->isConfigured()) {
            return response()->view('payments.not-configured', ['provider' => 'PayPal'], 503);
        }

        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($this->baseUrl().'/v2/checkout/orders', [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [
                        [
                            // custom_id ties the PayPal order back to this
                            // Payment; verified again on capture.
                            'custom_id' => (string) $payment->getKey(),
                            // `amount` is the payer total INCLUDING any
                            // passed-through PayPal fee (base_amount +
                            // provider_fee_amount, disclosed at checkout);
                            // captureMatches() verifies the capture against it.
                            'amount' => [
                                'currency_code' => $payment->currency,
                                'value' => self::formatAmount($payment->amount),
                            ],
                        ],
                    ],
                    'application_context' => [
                        // Signed: the return/cancel legs are public browser
                        // redirects, so only links we minted may act.
                        'return_url' => URL::temporarySignedRoute('payments.paypal.return', now()->addDay(), ['payment' => $payment->getKey()]),
                        'cancel_url' => URL::temporarySignedRoute('payments.paypal.cancel', now()->addDay(), ['payment' => $payment->getKey()]),
                    ],
                ]);

            if ($response->failed()) {
                throw new \RuntimeException('PayPal order creation failed: '.$response->body());
            }

            $order = $response->json();

            $approveLink = collect($order['links'] ?? [])
                ->firstWhere('rel', 'approve')['href'] ?? null;

            if (! $approveLink || ! ($order['id'] ?? null)) {
                throw new \RuntimeException('PayPal order response missing id or approve link.');
            }

            $payment->update(['provider_reference' => $order['id']]);

            return redirect()->away($approveLink);
        } catch (Throwable $e) {
            Log::error('PayPal initiate failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

            $payment->markFailed();

            return response()->view('payments.paypal.failed', ['payment' => $payment], 502);
        }
    }

    public function handleReturn(Request $request, Payment $payment): Response
    {
        // Only ever capture the order WE created for this payment. The
        // `token` query param is attacker-controlled: capturing it blindly
        // would let a cheap order A complete an expensive payment B.
        $orderId = (string) $payment->provider_reference;
        $token = $request->query('token');

        if ($orderId === '' || ($token !== null && (! is_string($token) || ! hash_equals($orderId, $token)))) {
            Log::warning('PayPal return token does not match the payment order', ['payment_id' => $payment->id]);

            return response()->view('payments.paypal.failed', ['payment' => $payment], 400);
        }

        if (! $payment->isPending()) {
            return response()->view('payments.paypal.failed', ['payment' => $payment], 400);
        }

        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($this->baseUrl()."/v2/checkout/orders/{$orderId}/capture", [
                    'headers' => 'Content-Type: application/json',
                ]);

            $data = $response->json();

            if ($response->successful() && ($data['status'] ?? null) === 'COMPLETED') {
                $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? [];
                $captureId = $capture['id'] ?? $orderId;

                if (! $this->captureMatches($payment, $capture, $data['purchase_units'][0] ?? [])) {
                    return response()->view('payments.paypal.failed', ['payment' => $payment], 400);
                }

                // Completion goes through the CommandBus (billing engine M2) so
                // markCompleted() + the PaymentCompleted outbox event are one
                // transaction, regardless of whether the browser return leg or
                // the async webhook lands first (activation is idempotent).
                app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand(
                    paymentId: $payment->getKey(),
                    providerReference: $captureId,
                ));

                return response()->view('payments.paypal.success', ['payment' => $payment->fresh()]);
            }

            $payment->markFailed();

            return response()->view('payments.paypal.failed', ['payment' => $payment], 400);
        } catch (Throwable $e) {
            Log::error('PayPal capture failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

            $payment->markFailed();

            return response()->view('payments.paypal.failed', ['payment' => $payment], 502);
        }
    }

    public function handleCancel(Request $request, Payment $payment): Response
    {
        $payment->markFailed();

        return response()->view('payments.paypal.failed', ['payment' => $payment], 200);
    }

    public function handleWebhook(Request $request): Response
    {
        if (! $this->isConfigured()) {
            Log::warning('PayPal webhook received while the gateway is not configured — ignoring.');

            return $this->jsonResponse(['error' => 'gateway not configured'], 503);
        }

        $payload = $request->all();

        if (! isset($payload['event_type']) || ! isset($payload['resource'])) {
            return $this->jsonResponse(['error' => 'invalid payload'], 400);
        }

        $webhookId = $this->cfg()['webhook_id'] ?? null;

        if (! filled($webhookId)) {
            return $this->jsonResponse(['error' => 'webhook not configured'], 400);
        }

        try {
            $accessToken = $this->getAccessToken();

            $verification = Http::withToken($accessToken)
                ->acceptJson()
                ->post($this->baseUrl().'/v1/notifications/verify-webhook-signature', [
                    'transmission_id' => $request->header('Paypal-Transmission-Id'),
                    'transmission_time' => $request->header('Paypal-Transmission-Time'),
                    'transmission_sig' => $request->header('Paypal-Transmission-Sig'),
                    'cert_url' => $request->header('Paypal-Cert-Url'),
                    'auth_algo' => $request->header('Paypal-Auth-Algo'),
                    'webhook_id' => $webhookId,
                    'webhook_event' => $payload,
                ]);

            if ($verification->failed() || ($verification->json('verification_status') !== 'SUCCESS')) {
                return $this->jsonResponse(['error' => 'signature verification failed'], 400);
            }
        } catch (Throwable $e) {
            Log::error('PayPal webhook verification failed', ['error' => $e->getMessage()]);

            return $this->jsonResponse(['error' => 'signature verification failed'], 400);
        }

        $eventType = $payload['event_type'];
        $resource = $payload['resource'];

        $orderId = $resource['supplementary_data']['related_ids']['order_id']
            ?? $resource['id']
            ?? null;

        if (! $orderId) {
            return $this->jsonResponse(['status' => 'ignored']);
        }

        $payment = Payment::where('provider_reference', $orderId)->first();

        if (! $payment) {
            return $this->jsonResponse(['status' => 'ignored']);
        }

        // Only a completed capture settles a payment — CHECKOUT.ORDER.APPROVED
        // means the payer approved but no funds have moved yet (the return
        // leg captures). The captured amount must match ours.
        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
            if (! $this->captureMatches($payment, $resource)) {
                return $this->jsonResponse(['status' => 'rejected']);
            }

            $captureId = $resource['id'] ?? $orderId;
            app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand(
                paymentId: $payment->getKey(),
                providerReference: $captureId,
            ));
        } elseif (in_array($eventType, ['PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED', 'CHECKOUT.ORDER.VOIDED'], true)) {
            $payment->markFailed();
        }

        return $this->jsonResponse(['status' => 'ok']);
    }

    /**
     * Verify a capture really pays for this payment: same amount and
     * currency, and (when PayPal echoes it) our custom_id. Logs critical and
     * returns false on any mismatch so the caller does not complete.
     *
     * @param  array<string, mixed>  $capture
     * @param  array<string, mixed>  $purchaseUnit
     */
    private function captureMatches(Payment $payment, array $capture, array $purchaseUnit = []): bool
    {
        $value = $capture['amount']['value'] ?? null;
        $currency = $capture['amount']['currency_code'] ?? null;
        $customId = $capture['custom_id'] ?? $purchaseUnit['custom_id'] ?? null;

        $amountOk = $value !== null
            && self::formatAmount($value) === self::formatAmount($payment->amount)
            && is_string($currency)
            && strtoupper($currency) === strtoupper((string) $payment->currency);
        $customOk = $customId === null || (string) $customId === (string) $payment->getKey();

        if ($amountOk && $customOk) {
            return true;
        }

        Log::critical('PayPal capture does not match payment — not completing', [
            'payment_id' => $payment->id,
            'expected_amount' => self::formatAmount($payment->amount),
            'expected_currency' => $payment->currency,
            'captured_amount' => $value,
            'captured_currency' => $currency,
            'custom_id' => $customId,
        ]);

        return false;
    }

    private static function formatAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function jsonResponse(array $data, int $status = 200): Response
    {
        return new Response(json_encode($data), $status, ['Content-Type' => 'application/json']);
    }

    private function baseUrl(): string
    {
        return ($this->cfg()['environment'] ?? 'sandbox') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    private function getAccessToken(): string
    {
        $cfg = $this->cfg();

        $response = Http::asForm()
            ->withBasicAuth($cfg['client_id'] ?? '', $cfg['client_secret'] ?? '')
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if ($response->failed() || ! $response->json('access_token')) {
            throw new \RuntimeException('Failed to obtain PayPal access token: '.$response->body());
        }

        return $response->json('access_token');
    }
}
