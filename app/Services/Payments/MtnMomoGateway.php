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
use Illuminate\Support\Str;

/**
 * MTN Mobile Money (MoMo) Collections "Request to Pay" gateway.
 *
 * Flow:
 *  1. initiate() checks isConfigured(); if not, shows the shared
 *     "not configured" fallback view. If configured, shows a form asking
 *     for the payer's MSISDN (phone number), since Collections' Request to
 *     Pay needs a phone number that PaymentCheckoutController never has.
 *  2. submit() (routes/payments/mtn-momo.php) takes that phone number,
 *     fetches an access token via Basic auth (api_user/api_key), then
 *     POSTs to /collection/v1_0/requesttopay with a fresh X-Reference-Id
 *     UUID, storing that UUID on Payment::provider_reference so the
 *     webhook can match it back.
 *  3. handleWebhook() receives MTN's asynchronous status callback and
 *     marks the matching Payment completed/failed.
 */
class MtnMomoGateway implements PaymentGatewayContract
{
    public function isConfigured(): bool
    {
        return GatewayCredentials::isConfigured(PaymentProvider::MtnMomo);
    }

    public function initiate(Payment $payment): RedirectResponse|Response
    {
        if (! $this->isConfigured()) {
            return response()->view('payments.not-configured', [
                'provider' => 'MTN Mobile Money',
            ], 503);
        }

        return response()->view('payments.mtn-momo.request-phone', [
            'payment' => $payment,
        ]);
    }

    /**
     * Handle the payer-phone-number form submission: request an access
     * token, then submit the Request to Pay call to MTN's Collections API.
     */
    public function submit(Request $request, Payment $payment): Response
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:8', 'max:20'],
        ]);

        if (! $this->isConfigured()) {
            return response()->view('payments.not-configured', [
                'provider' => 'MTN Mobile Money',
            ], 503);
        }

        // Re-submitting a settled/failed payment would overwrite its
        // provider_reference and re-push a charge.
        if (! $payment->isPending()) {
            return response()->view('payments.mtn-momo.failed', ['payment' => $payment], 409);
        }

        return match ($this->requestToPay($payment, $data['phone'])) {
            'pending' => response()->view('payments.mtn-momo.pending', ['payment' => $payment], 200),
            default => response()->view('payments.mtn-momo.failed', ['payment' => $payment], 502),
        };
    }

    /**
     * Fire the MoMo Collections "Request to Pay" push for this pending
     * Payment against the given payer MSISDN. Returns 'pending' once MTN has
     * accepted the request (the payer must still approve on their phone —
     * final status arrives via handleWebhook()), or 'failed'. Callable both
     * from submit() (the standalone phone form) and from the self-serve
     * checkout picker via PaymentCheckoutController (billing engine M11).
     */
    public function requestToPay(Payment $payment, string $phone): string
    {
        if (! $this->isConfigured()) {
            $payment->markFailed();

            return 'failed';
        }

        $config = GatewayCredentials::for(PaymentProvider::MtnMomo);
        $baseUrl = $this->baseUrl($config['environment']);
        $referenceId = (string) Str::uuid();

        try {
            $token = $this->fetchAccessToken($baseUrl, $config);

            if (! $token) {
                $payment->markFailed();

                return 'failed';
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'X-Reference-Id' => $referenceId,
                'X-Target-Environment' => $config['target_environment'],
                'Ocp-Apim-Subscription-Key' => $config['subscription_key'],
                'Content-Type' => 'application/json',
            ])->post("{$baseUrl}/collection/v1_0/requesttopay", [
                'amount' => (string) $payment->amount,
                'currency' => $config['currency'],
                'externalId' => (string) $payment->id,
                'payer' => [
                    'partyIdType' => 'MSISDN',
                    'partyId' => preg_replace('/\D+/', '', $phone),
                ],
                'payerMessage' => "Payment for plan #{$payment->plan_id}",
                'payeeNote' => "Payment #{$payment->id}",
                'callbackUrl' => rtrim((string) $config['callback_host'], '/').route('payments.mtn-momo.webhook', [], false),
            ]);

            // MTN's requesttopay returns 202 Accepted with no body on success.
            if (! $response->successful()) {
                Log::warning('MTN MoMo requesttopay failed', [
                    'payment_id' => $payment->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $payment->markFailed();

                return 'failed';
            }

            $payment->update([
                'provider_reference' => $referenceId,
            ]);

            return 'pending';
        } catch (\Throwable $e) {
            Log::error('MTN MoMo requesttopay exception', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);

            $payment->markFailed();

            return 'failed';
        }
    }

    /**
     * MTN MoMo callbacks are unauthenticated by design (no signature, no
     * shared secret). The payload is therefore only a *hint*: its `status`
     * is never trusted. We re-query `GET /collection/v1_0/requesttopay/{ref}`
     * (OAuth + subscription-key authenticated) and act only on the status
     * MTN itself reports, and only complete when the amount matches ours.
     */
    public function handleWebhook(Request $request): Response
    {
        if (! $this->isConfigured()) {
            Log::warning('MTN MoMo webhook received while the gateway is not configured — ignoring.');

            return $this->jsonResponse(['message' => 'Gateway not configured.'], 503);
        }

        $referenceId = $request->input('referenceId') ?? $request->input('externalId');

        if (! is_string($referenceId) || $referenceId === '') {
            Log::warning('MTN MoMo webhook received with missing required fields', [
                'payload' => $request->all(),
            ]);

            return $this->jsonResponse(['message' => 'Invalid payload.'], 422);
        }

        $payment = Payment::where('provider', 'mtn_momo')
            ->where('provider_reference', $referenceId)
            ->first();

        if (! $payment) {
            Log::warning('MTN MoMo webhook received for unknown payment reference', [
                'referenceId' => $referenceId,
            ]);

            return $this->jsonResponse(['message' => 'No matching payment.'], 404);
        }

        $confirmed = $this->fetchRequestToPayStatus($payment);

        if ($confirmed === null) {
            return $this->jsonResponse(['message' => 'Unable to verify transaction status.'], 502);
        }

        $normalizedStatus = strtoupper((string) ($confirmed['status'] ?? ''));

        if ($normalizedStatus === 'SUCCESSFUL') {
            if (! PaymentAmount::matches($payment, $confirmed['amount'] ?? null)) {
                Log::critical('MTN MoMo confirmed SUCCESSFUL with a mismatched amount — refusing to complete', [
                    'payment_id' => $payment->id,
                    'expected' => (string) $payment->amount,
                    'confirmed' => $confirmed['amount'] ?? null,
                ]);

                return $this->jsonResponse(['message' => 'Amount mismatch.'], 409);
            }

            // Mirror StripeGateway: funnel completion through the CommandBus so
            // Payment::markCompleted() + the PaymentCompleted outbox event
            // happen in one transaction (billing engine M2). Idempotent
            // downstream via SubscriptionService::activateFromPayment().
            app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand(
                paymentId: $payment->getKey(),
                providerReference: $referenceId,
            ));
        } elseif (in_array($normalizedStatus, ['FAILED', 'REJECTED', 'TIMEOUT'], true)) {
            $payment->markFailed();
        } else {
            Log::info('MTN MoMo webhook received with unrecognized status', [
                'payment_id' => $payment->id,
                'status' => $normalizedStatus,
            ]);
        }

        return $this->jsonResponse(['message' => 'ok']);
    }

    /**
     * Server-to-server status check: `GET /collection/v1_0/requesttopay/{referenceId}`.
     * Returns the decoded body, or null when MTN could not be reached.
     *
     * @return array<string, mixed>|null
     */
    public function fetchRequestToPayStatus(Payment $payment): ?array
    {
        $config = GatewayCredentials::for(PaymentProvider::MtnMomo);
        $baseUrl = $this->baseUrl($config['environment']);

        try {
            $token = $this->fetchAccessToken($baseUrl, $config);

            if (! $token) {
                return null;
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'X-Target-Environment' => $config['target_environment'],
                'Ocp-Apim-Subscription-Key' => $config['subscription_key'],
            ])->get("{$baseUrl}/collection/v1_0/requesttopay/".rawurlencode((string) $payment->provider_reference));

            if (! $response->successful() || ! is_array($response->json())) {
                Log::warning('MTN MoMo requesttopay status check failed', [
                    'payment_id' => $payment->id,
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::error('MTN MoMo requesttopay status exception', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The contract's handleWebhook() return type is Illuminate\Http\Response,
     * which Illuminate\Http\JsonResponse (returned by response()->json())
     * does not extend — so we build a plain Response with a JSON body/header.
     */
    private function jsonResponse(array $payload, int $status = 200): Response
    {
        return response(json_encode($payload), $status, [
            'Content-Type' => 'application/json',
        ]);
    }

    private function baseUrl(string $environment): string
    {
        return $environment === 'production'
            ? 'https://proxy.momoapi.mtn.com'
            : 'https://sandbox.momodeveloper.mtn.com';
    }

    private function fetchAccessToken(string $baseUrl, array $config): ?string
    {
        $response = Http::withBasicAuth($config['api_user'], $config['api_key'])
            ->withHeaders([
                'Ocp-Apim-Subscription-Key' => $config['subscription_key'],
            ])
            ->post("{$baseUrl}/collection/token/");

        if (! $response->successful()) {
            Log::warning('MTN MoMo access token request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json('access_token');
    }
}
