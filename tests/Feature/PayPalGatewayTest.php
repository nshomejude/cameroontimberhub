<?php

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\PayPalGateway;
use Illuminate\Support\Facades\Http;

function fakePayPalConfig(): void
{
    config([
        'payments.paypal.environment' => 'sandbox',
        'payments.paypal.client_id' => 'test-client-id',
        'payments.paypal.client_secret' => 'test-client-secret',
        'payments.paypal.webhook_id' => 'test-webhook-id',
        'payments.paypal.currency' => 'USD',
    ]);
}

test('isConfigured is false when credentials are missing', function () {
    config([
        'payments.paypal.client_id' => null,
        'payments.paypal.client_secret' => null,
    ]);

    expect((new PayPalGateway)->isConfigured())->toBeFalse();
});

test('isConfigured is true when credentials are present', function () {
    fakePayPalConfig();

    expect((new PayPalGateway)->isConfigured())->toBeTrue();
});

test('checkout start with unconfigured paypal shows not configured response', function () {
    config([
        'payments.paypal.client_id' => null,
        'payments.paypal.client_secret' => null,
    ]);

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user);
    $plan = Plan::factory()->create();

    $response = $this->actingAs($user)->post(route('payments.checkout', $plan), [
        'provider' => PaymentProvider::PayPal->value,
    ]);

    $response->assertStatus(503);
    $response->assertSee('PayPal');
});

test('initiate creates an order and redirects to the approve link, storing the order id', function () {
    fakePayPalConfig();

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-access-token', 'expires_in' => 3600], 200),
        '*/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-ABC-123',
            'status' => 'CREATED',
            'links' => [
                ['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-ABC-123', 'rel' => 'self', 'method' => 'GET'],
                ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-ABC-123', 'rel' => 'approve', 'method' => 'GET'],
                ['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/ORDER-ABC-123/capture', 'rel' => 'capture', 'method' => 'POST'],
            ],
        ], 201),
    ]);

    $payment = Payment::factory()->create(['provider' => PaymentProvider::PayPal, 'amount' => 49.99, 'currency' => 'USD']);

    $response = (new PayPalGateway)->initiate($payment);

    expect($response->getTargetUrl())->toBe('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-ABC-123');

    $payment->refresh();
    expect($payment->provider_reference)->toBe('ORDER-ABC-123');
    expect($payment->status)->toBe(PaymentStatus::Pending);
});

test('initiate marks payment failed when order creation fails', function () {
    fakePayPalConfig();

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-access-token'], 200),
        '*/v2/checkout/orders' => Http::response(['error' => 'invalid_request'], 400),
    ]);

    $payment = Payment::factory()->create(['provider' => PaymentProvider::PayPal]);

    $response = (new PayPalGateway)->initiate($payment);

    expect($response->getStatusCode())->toBe(502);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Failed);
});

test('return route captures the order and marks payment completed when status is COMPLETED', function () {
    fakePayPalConfig();

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-access-token'], 200),
        '*/v2/checkout/orders/ORDER-ABC-123/capture' => Http::response([
            'id' => 'ORDER-ABC-123',
            'status' => 'COMPLETED',
            'purchase_units' => [
                [
                    'payments' => [
                        'captures' => [
                            ['id' => 'CAPTURE-XYZ-789', 'status' => 'COMPLETED'],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $payment = Payment::factory()->create(['provider' => PaymentProvider::PayPal, 'provider_reference' => 'ORDER-ABC-123']);

    $response = $this->get(route('payments.paypal.return', $payment).'?token=ORDER-ABC-123');

    $response->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Completed);
    expect($payment->provider_reference)->toBe('CAPTURE-XYZ-789');
});

test('return route marks payment failed when capture status is not COMPLETED', function () {
    fakePayPalConfig();

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-access-token'], 200),
        '*/v2/checkout/orders/ORDER-ABC-123/capture' => Http::response([
            'id' => 'ORDER-ABC-123',
            'status' => 'VOIDED',
        ], 200),
    ]);

    $payment = Payment::factory()->create(['provider' => PaymentProvider::PayPal, 'provider_reference' => 'ORDER-ABC-123']);

    $response = $this->get(route('payments.paypal.return', $payment).'?token=ORDER-ABC-123');

    $response->assertStatus(400);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Failed);
});

test('webhook rejects payload when signature verification does not report SUCCESS', function () {
    fakePayPalConfig();

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-access-token'], 200),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE'], 200),
    ]);

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::PayPal,
        'provider_reference' => 'ORDER-ABC-123',
        'status' => PaymentStatus::Pending,
    ]);

    $response = $this->postJson(route('payments.paypal.webhook'), [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => 'CAPTURE-XYZ-789',
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-ABC-123']],
        ],
    ]);

    $response->assertStatus(400);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Pending);
});

test('webhook rejects payload with invalid shape', function () {
    fakePayPalConfig();

    $response = $this->postJson(route('payments.paypal.webhook'), ['foo' => 'bar']);

    $response->assertStatus(400);
});

test('webhook marks payment completed on verified capture completed event', function () {
    fakePayPalConfig();

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-access-token'], 200),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
    ]);

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::PayPal,
        'provider_reference' => 'ORDER-ABC-123',
        'status' => PaymentStatus::Pending,
    ]);

    $response = $this->postJson(route('payments.paypal.webhook'), [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => 'CAPTURE-XYZ-789',
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-ABC-123']],
        ],
    ]);

    $response->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Completed);
});
