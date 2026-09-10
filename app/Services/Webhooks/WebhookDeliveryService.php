<?php

namespace App\Services\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Http;

/**
 * Signs and delivers a single webhook HTTP request (architecture plan,
 * Task 0.5), following Stripe/GitHub conventions:
 *
 *  - Every delivery body is a standard event envelope:
 *      {"id":"evt_<ulid>","type":"<event.type>","created":<unix>,"data":{...}}
 *    `id` is derived from the WebhookDelivery row's `event_id` (a ULID
 *    generated once when the row is created), so all 3 retry attempts of the
 *    same delivery carry the SAME `id` — the consumer's idempotency key.
 *
 *  - The envelope is JSON-encoded ONCE with a fixed flag set
 *    (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); that exact string is
 *    both signed and sent as the raw request body — the signed bytes and the
 *    transmitted bytes are guaranteed identical.
 *
 *  - Signature (Stripe scheme, replay-protected): HMAC-SHA256 over
 *    "<timestamp>.<body>" keyed by the subscription's plaintext `secret`
 *    (stored encrypted at rest). Sent as
 *      X-CTH-Signature: t=<unix>,v1=<hex>
 *    A consumer recomputes hmac_sha256(t + "." + rawBody, secret), constant-
 *    time compares against v1, and rejects deliveries whose `t` is older than
 *    a tolerance (5 minutes recommended).
 *
 *  - Companion headers: X-CTH-Event, X-CTH-Delivery (= envelope id),
 *    X-CTH-Timestamp.
 */
class WebhookDeliveryService
{
    /** Flags used for the one canonical encode of every envelope — signed AND sent. */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** Envelope id for a delivery row (stable across retries). */
    public function envelopeId(WebhookDelivery $delivery): string
    {
        return 'evt_'.$delivery->event_id;
    }

    /** Builds the standard event envelope for a delivery at the given unix timestamp. */
    public function envelope(WebhookDelivery $delivery, int $timestamp): array
    {
        return [
            'id' => $this->envelopeId($delivery),
            'type' => $delivery->event_type,
            'created' => $timestamp,
            'data' => $delivery->payload ?? [],
        ];
    }

    /** The one canonical JSON encoding of an envelope — this exact string is signed and sent. */
    public function encode(array $envelope): string
    {
        return json_encode($envelope, self::JSON_FLAGS);
    }

    /** HMAC-SHA256 over "<timestamp>.<body>" keyed by the plaintext secret. */
    public function sign(int $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Delivers the envelope and returns the HTTP response code, or null if
     * the request could not be completed at all (connection error/timeout).
     */
    public function deliver(WebhookSubscription $subscription, WebhookDelivery $delivery): ?int
    {
        $timestamp = now()->getTimestamp();
        $body = $this->encode($this->envelope($delivery, $timestamp));
        $signature = $this->sign($timestamp, $body, (string) $subscription->secret);

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-CTH-Signature' => "t={$timestamp},v1={$signature}",
                    'X-CTH-Event' => $delivery->event_type,
                    'X-CTH-Delivery' => $this->envelopeId($delivery),
                    'X-CTH-Timestamp' => (string) $timestamp,
                ])
                ->withBody($body, 'application/json')
                ->post($subscription->url);

            return $response->status();
        } catch (\Throwable) {
            return null;
        }
    }
}
