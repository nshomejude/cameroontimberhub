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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Orange Money Web Payment API gateway (hosted-checkout-page flow).
 *
 * Flow:
 *  1. initiate() checks isConfigured(); if not, shows the shared
 *     "not configured" fallback view. If configured, it:
 *       a. fetches an OAuth access token from Orange's auth endpoint using
 *          client_id/client_secret (Basic auth, client_credentials grant),
 *       b. calls the "webpayment" init endpoint with the merchant_key,
 *          amount, currency, order id, and return/cancel/notify URLs,
 *       c. stores the returned pay_token on Payment::provider_reference,
 *          and redirects the payer to the returned payment_url (a real
 *          Orange-hosted domain, hence redirect()->away()).
 *  2. handleWebhook() (routes/payments/orange-money.php, "notify" route)
 *     receives Orange's asynchronous IPN-style notification and marks the
 *     matching Payment completed/failed based on the payload's status.
 *  3. returnPage() is the payer-facing landing page after Orange redirects
 *     them back to the browser. It is purely informational — actual status
 *     updates only ever come from handleWebhook(), never from the payer's
 *     browser redirect, since a closed tab shouldn't be trusted as the
 *     source of truth for payment success.
 */
class OrangeMoneyGateway implements PaymentGatewayContract
{
    public function isConfigured(): bool
    {
        return GatewayCredentials::isConfigured(PaymentProvider::OrangeMoney);
    }

    public function initiate(Payment $payment): RedirectResponse|Response
    {
        if (! $this->isConfigured()) {
            return response()->view('payments.not-configured', [
                'provider' => 'Orange Money',
            ], 503);
        }

        $config = GatewayCredentials::for(PaymentProvider::OrangeMoney);
        $baseUrl = $this->baseUrl($config['environment']);

        try {
            $token = $this->fetchAccessToken($baseUrl, $config);

            if (! $token) {
                $payment->markFailed();

                return response()->view('payments.orange-money.failed', [
                    'payment' => $payment,
                ], 502);
            }

            $response = Http::withToken($token)->post("{$baseUrl}/webpayment", [
                'merchant_key' => $config['merchant_key'],
                'currency' => $config['currency'],
                'order_id' => (string) $payment->id,
                'amount' => (string) $payment->amount,
                'return_url' => route('payments.orange-money.return', ['payment' => $payment->id]),
                'cancel_url' => route('payments.orange-money.return', ['payment' => $payment->id]),
                'notif_url' => route('payments.orange-money.notify'),
                'lang' => 'fr',
                'reference' => "Payment for plan #{$payment->plan_id}",
            ]);

            if (! $response->successful() || ! $response->json('payment_url') || ! $response->json('pay_token')) {
                Log::warning('Orange Money webpayment init failed', [
                    'payment_id' => $payment->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $payment->markFailed();

                return response()->view('payments.orange-money.failed', [
                    'payment' => $payment,
                ], 502);
            }

            $payment->update([
                'provider_reference' => $response->json('pay_token'),
            ]);

            return redirect()->away($response->json('payment_url'));
        } catch (\Throwable $e) {
            Log::error('Orange Money webpayment init exception', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);

            $payment->markFailed();

            return response()->view('payments.orange-money.failed', [
                'payment' => $payment,
            ], 502);
        }
    }

    /**
     * Payer-facing landing page after Orange redirects the browser back.
     * Purely informational — never updates Payment status itself.
     */
    public function returnPage(Payment $payment): Response
    {
        return response()->view('payments.orange-money.return', [
            'payment' => $payment,
        ]);
    }

    /**
     * NOTE: We don't have real Orange merchant-portal webhook credentials
     * to verify a signature against yet. Until those details are
     * available, we only validate the payload's required-field shape and
     * log anything unexpected rather than trusting it blindly. A
     * production hardening pass should add signature verification and/or
     * IP allowlisting once Orange provides them for this merchant account.
     */
    public function handleWebhook(Request $request): Response
    {
        if (! $this->isConfigured()) {
            Log::warning('Orange Money webhook received while the gateway is not configured — ignoring.');

            return response(['message' => 'Gateway not configured.'], 503);
        }

        $payToken = $request->input('pay_token') ?? $request->input('order_id');
        $status = $request->input('status');

        if (! $payToken || ! $status) {
            Log::warning('Orange Money webhook received with missing required fields', [
                'payload' => $request->all(),
            ]);

            return response(['message' => 'Invalid payload.'], 422);
        }

        $payment = Payment::where('provider', 'orange_money')
            ->where('provider_reference', $payToken)
            ->first();

        if (! $payment) {
            Log::warning('Orange Money webhook received for unknown payment reference', [
                'pay_token' => $payToken,
            ]);

            return response(['message' => 'No matching payment.'], 404);
        }

        $normalizedStatus = strtoupper((string) $status);

        if ($normalizedStatus === 'SUCCESS') {
            // Mirror StripeGateway: completion goes through the CommandBus so
            // markCompleted() + the PaymentCompleted outbox event are one
            // transaction (billing engine M2).
            app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand(
                paymentId: $payment->getKey(),
                providerReference: $payToken,
            ));
        } elseif (in_array($normalizedStatus, ['FAILED', 'EXPIRED', 'CANCELLED'], true)) {
            $payment->markFailed();
        } else {
            Log::info('Orange Money webhook received with unrecognized status', [
                'payment_id' => $payment->id,
                'status' => $normalizedStatus,
            ]);
        }

        return response(['message' => 'ok']);
    }

    private function baseUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://api.orange.com/orange-money-webpay/cm/v1'
            : 'https://api.orange.com/orange-money-webpay/dev/v1';
    }

    private function fetchAccessToken(string $baseUrl, array $config): ?string
    {
        $authUrl = 'https://api.orange.com/oauth/v3/token';

        $response = Http::asForm()
            ->withBasicAuth($config['client_id'], $config['client_secret'])
            ->post($authUrl, [
                'grant_type' => 'client_credentials',
            ]);

        if (! $response->successful()) {
            Log::warning('Orange Money access token request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json('access_token');
    }
}
