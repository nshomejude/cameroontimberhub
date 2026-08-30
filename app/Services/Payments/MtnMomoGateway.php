<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayContract;
use App\Models\Payment;
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
        $config = config('payments.mtn_momo');

        return filled($config['subscription_key'] ?? null)
            && filled($config['api_user'] ?? null)
            && filled($config['api_key'] ?? null);
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

        $config = config('payments.mtn_momo');
        $baseUrl = $this->baseUrl($config['environment']);
        $referenceId = (string) Str::uuid();

        try {
            $token = $this->fetchAccessToken($baseUrl, $config);

            if (! $token) {
                $payment->markFailed();

                return response()->view('payments.mtn-momo.failed', [
                    'payment' => $payment,
                ], 502);
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
                    'partyId' => preg_replace('/\D+/', '', $data['phone']),
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

                return response()->view('payments.mtn-momo.failed', [
                    'payment' => $payment,
                ], 502);
            }

            $payment->update([
                'provider_reference' => $referenceId,
            ]);

            return response()->view('payments.mtn-momo.pending', [
                'payment' => $payment,
            ]);
        } catch (\Throwable $e) {
            Log::error('MTN MoMo requesttopay exception', [
                'payment_id' => $payment->id,
                'message' => $e->getMessage(),
            ]);

            $payment->markFailed();

            return response()->view('payments.mtn-momo.failed', [
                'payment' => $payment,
            ], 502);
        }
    }

    /**
     * NOTE: MTN MoMo has no widely-adopted webhook-signature standard the
     * way Stripe does. Until real webhook-secret / IP-allowlist details are
     * available from MTN's merchant portal, we only validate the payload
     * shape and log anything unexpected rather than trusting it blindly.
     * A production hardening pass should add IP allowlisting and/or a
     * shared-secret header check once MTN provides one for this merchant
     * account.
     */
    public function handleWebhook(Request $request): Response
    {
        $referenceId = $request->input('referenceId') ?? $request->input('externalId');
        $status = $request->input('status');

        if (! $referenceId || ! $status) {
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

        $normalizedStatus = strtoupper((string) $status);

        if ($normalizedStatus === 'SUCCESSFUL') {
            $payment->markCompleted($referenceId);
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
