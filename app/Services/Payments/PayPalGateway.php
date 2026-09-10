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
                            'amount' => [
                                'currency_code' => $payment->currency,
                                'value' => number_format((float) $payment->amount, 2, '.', ''),
                            ],
                        ],
                    ],
                    'application_context' => [
                        'return_url' => route('payments.paypal.return', $payment),
                        'cancel_url' => route('payments.paypal.cancel', $payment),
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
        $orderId = $request->query('token') ?? $payment->provider_reference;

        if (! $orderId) {
            $payment->markFailed();

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
                $captureId = $data['purchase_units'][0]['payments']['captures'][0]['id'] ?? $orderId;

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

        if ($eventType === 'PAYMENT.CAPTURE.COMPLETED' || $eventType === 'CHECKOUT.ORDER.APPROVED') {
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
