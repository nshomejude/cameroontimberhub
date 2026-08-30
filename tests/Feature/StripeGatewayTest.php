<?php

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\StripeGateway;

/**
 * Coverage notes: Stripe's SDK does real outbound HTTP for Checkout
 * Session creation via a plain cURL client that isn't trivially swappable
 * with Http::fake() in this environment, so the "would send the right
 * params to Stripe" path is not exercised end-to-end here. What IS fully
 * covered without needing live credentials or faking Stripe's internal
 * transport:
 *  - isConfigured() true/false based on config presence.
 *  - The shared checkout controller's "not configured" fallback (503) when
 *    Stripe has no secret key.
 *  - Webhook signature verification, using Stripe's own signing scheme
 *    (HMAC-SHA256 over "{timestamp}.{payload}") to build both a valid and
 *    an invalid signature — this is pure crypto under our control and
 *    needs no network access.
 */
function stripeSignatureHeader(string $payload, string $secret, ?int $timestamp = null): string
{
    $timestamp ??= time();
    $signedPayload = $timestamp.'.'.$payload;
    $signature = hash_hmac('sha256', $signedPayload, $secret);

    return "t={$timestamp},v1={$signature}";
}

it('is not configured when the secret key is missing', function () {
    config(['payments.stripe.secret_key' => null]);

    expect((new StripeGateway())->isConfigured())->toBeFalse();
});

it('is configured when the secret key is present', function () {
    config(['payments.stripe.secret_key' => 'sk_test_123']);

    expect((new StripeGateway())->isConfigured())->toBeTrue();
});

it('shows the not-configured view when starting checkout without a secret key', function () {
    config(['payments.stripe.secret_key' => null]);

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user);

    $plan = Plan::factory()->create();

    $response = $this->actingAs($user)->post(route('payments.checkout', $plan), [
        'provider' => 'stripe',
    ]);

    $response->assertStatus(503);
    $response->assertSee('Stripe', false);
});

it('rejects a webhook request with a missing signature header', function () {
    config(['payments.stripe.webhook_secret' => 'whsec_test_secret']);

    $payment = Payment::factory()->create();

    $payload = json_encode([
        'id' => 'evt_test',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_123',
            'payment_intent' => 'pi_test_123',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]],
    ]);

    $response = $this->call('POST', route('payments.stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('rejects a webhook request with an invalid signature', function () {
    config(['payments.stripe.webhook_secret' => 'whsec_test_secret']);

    $payment = Payment::factory()->create();

    $payload = json_encode([
        'id' => 'evt_test',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_123',
            'payment_intent' => 'pi_test_123',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]],
    ]);

    $badSignature = stripeSignatureHeader($payload, 'wrong_secret');

    $response = $this->call('POST', route('payments.stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $badSignature,
    ], $payload);

    $response->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('accepts a validly signed checkout.session.completed webhook and marks the payment completed', function () {
    $secret = 'whsec_test_secret';
    config(['payments.stripe.webhook_secret' => $secret]);

    $payment = Payment::factory()->create();

    $payload = json_encode([
        'id' => 'evt_test',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_123',
            'payment_intent' => 'pi_test_123',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]],
    ]);

    $goodSignature = stripeSignatureHeader($payload, $secret);

    $response = $this->call('POST', route('payments.stripe.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $goodSignature,
    ], $payload);

    $response->assertStatus(200);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
    expect($payment->fresh()->provider_reference)->toBe('pi_test_123');
});
