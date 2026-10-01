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
 *     receives Orange's asynchronous IPN-style notification, re-queries
 *     Orange's transactionstatus API, and marks the matching Payment
 *     completed/failed based on THAT confirmed status (never the payload).
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
     * Orange's notification ("notif_url") is NOT authenticated — anyone who
     * knows a pay_token could POST {"status":"SUCCESS"}. So the payload is
     * treated purely as a *hint* that something changed: its `status` field
     * is never trusted. Instead we re-query Orange's WebPay
     * `transactionstatus` API (server-to-server, OAuth-authenticated) and
     * only complete the payment when Orange itself confirms SUCCESS for this
     * order id + pay_token AND the confirmed amount matches ours.
     */
    public function handleWebhook(Request $request): Response
    {
        if (! $this->isConfigured()) {
            Log::warning('Orange Money webhook received while the gateway is not configured — ignoring.');

            return response(['message' => 'Gateway not configured.'], 503);
        }

        $payToken = $request->input('pay_token') ?? $request->input('order_id');

        if (! is_string($payToken) || $payToken === '') {
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

        $confirmed = $this->fetchTransactionStatus($payment);

        if ($confirmed === null) {
            // Could not verify — let Orange retry later; no state change.
            return response(['message' => 'Unable to verify transaction status.'], 502);
        }

        $normalizedStatus = strtoupper((string) ($confirmed['status'] ?? ''));

        if ($normalizedStatus === 'SUCCESS') {
            if (! PaymentAmount::matches($payment, $confirmed['amount'] ?? null)) {
                Log::critical('Orange Money confirmed SUCCESS with a mismatched amount — refusing to complete', [
                    'payment_id' => $payment->id,
                    'expected' => (string) $payment->amount,
                    'confirmed' => $confirmed['amount'] ?? null,
                ]);

                return response(['message' => 'Amount mismatch.'], 409);
            }

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

    /**
     * Server-to-server status check (Orange WebPay `POST /transactionstatus`
     * keyed on order_id + amount + pay_token). Returns the decoded body, or
     * null when Orange could not be reached / answered with an error.
     *
     * @return array<string, mixed>|null
     */
    public function fetchTransactionStatus(Payment $payment): ?array
    {
        $config = GatewayCredentials::for(PaymentProvider::OrangeMoney);
        $baseUrl = $this->baseUrl($config['environment']);

        try {
            $token = $this->fetchAccessToken($baseUrl, $config);

            if (! $token) {
                return null;
            }

            $response = Http::withToken($token)->post("{$baseUrl}/transactionstatus", [
                'order_id' => (string) $payment->id,
                'amount' => (string) $payment->amount,
                'pay_token' => (string) $payment->provider_reference,
            ]);

            if (! $response->successful() || ! is_array($response->json())) {
                Log::warning('Orange Money transactionstatus failed', [
                    'payment_id' => $payment->id,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('Orange Money transactionstatus exception', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
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
