<?php

namespace App\Services\Webhooks;

use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Http;

/**
 * Signs and delivers a single webhook HTTP request (architecture plan,
 * Task 0.5). Signing key note: `WebhookSubscription::secret_hash` is a
 * one-way sha256 of the plaintext secret shown to the company exactly once
 * at subscription-creation time (mirrors how an API key's plaintext token
 * is never stored). Rather than needing a *reversible* stored secret to
 * HMAC-sign each delivery, this deliberately uses the stored hash itself as
 * the HMAC key: the company independently derives the same key by hashing
 * their own copy of the plaintext secret with sha256, so they can verify
 * `X-CTH-Signature` without CTH ever persisting the recoverable plaintext.
 */
class WebhookDeliveryService
{
    public function sign(array $payload, WebhookSubscription $subscription): string
    {
        return hash_hmac('sha256', json_encode($payload), $subscription->secret_hash);
    }

    /**
     * Delivers the payload and returns the HTTP response code, or null if
     * the request could not be completed at all (connection error/timeout).
     */
    public function deliver(WebhookSubscription $subscription, array $payload): ?int
    {
        $signature = $this->sign($payload, $subscription);

        try {
            $response = Http::timeout(5)
                ->withHeaders(['X-CTH-Signature' => 'sha256='.$signature])
                ->post($subscription->url, $payload);

            return $response->status();
        } catch (\Throwable) {
            return null;
        }
    }
}
