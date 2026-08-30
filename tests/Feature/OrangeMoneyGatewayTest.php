<?php

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\OrangeMoneyGateway;
use Illuminate\Support\Facades\Http;

function fakeOrangeMoneyConfig(): void
{
    config([
        'payments.orange_money.client_id' => 'test-client-id',
        'payments.orange_money.client_secret' => 'test-client-secret',
        'payments.orange_money.merchant_key' => 'test-merchant-key',
        'payments.orange_money.currency' => 'XAF',
        'payments.orange_money.environment' => 'sandbox',
    ]);
}

it('is not configured when credentials are missing', function () {
    config([
        'payments.orange_money.client_id' => null,
        'payments.orange_money.client_secret' => null,
        'payments.orange_money.merchant_key' => null,
    ]);

    expect((new OrangeMoneyGateway)->isConfigured())->toBeFalse();
});

it('is configured when all required credentials are present', function () {
    fakeOrangeMoneyConfig();

    expect((new OrangeMoneyGateway)->isConfigured())->toBeTrue();
});

it('shows the not-configured page and never fakes success when unconfigured', function () {
    config([
        'payments.orange_money.client_id' => null,
        'payments.orange_money.client_secret' => null,
        'payments.orange_money.merchant_key' => null,
    ]);

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user);
    $plan = Plan::factory()->create();

    $response = $this->actingAs($user)->post(route('payments.checkout', $plan), [
        'provider' => PaymentProvider::OrangeMoney->value,
    ]);

    $response->assertStatus(503);
    $response->assertSee("Orange Money");

    $payment = Payment::first();
    expect($payment->status)->toBe(PaymentStatus::Pending);
});

it('initiates a payment and redirects to the Orange payment_url on success', function () {
    fakeOrangeMoneyConfig();

    Http::fake([
        'api.orange.com/oauth/v3/token' => Http::response(['access_token' => 'fake-token'], 200),
        'api.orange.com/orange-money-webpay/*/webpayment' => Http::response([
            'payment_url' => 'https://webpayment.orange-money.com/checkout/abc123',
            'pay_token' => 'pay-token-abc123',
            'notif_token' => 'notif-abc123',
        ], 200),
    ]);

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::OrangeMoney,
        'status' => PaymentStatus::Pending,
    ]);

    $response = (new OrangeMoneyGateway)->initiate($payment);

    expect($response->getTargetUrl())->toBe('https://webpayment.orange-money.com/checkout/abc123');

    $payment->refresh();
    expect($payment->provider_reference)->toBe('pay-token-abc123')
        ->and($payment->status)->toBe(PaymentStatus::Pending);
});

it('marks the payment failed and shows an error when the webpayment init call fails', function () {
    fakeOrangeMoneyConfig();

    Http::fake([
        'api.orange.com/oauth/v3/token' => Http::response(['access_token' => 'fake-token'], 200),
        'api.orange.com/orange-money-webpay/*/webpayment' => Http::response(['message' => 'invalid merchant_key'], 400),
    ]);

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::OrangeMoney,
        'status' => PaymentStatus::Pending,
    ]);

    $response = (new OrangeMoneyGateway)->initiate($payment);

    expect($response->getStatusCode())->toBe(502);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Failed);
});

it('marks the payment failed when the oauth token request fails', function () {
    fakeOrangeMoneyConfig();

    Http::fake([
        'api.orange.com/oauth/v3/token' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::OrangeMoney,
        'status' => PaymentStatus::Pending,
    ]);

    $response = (new OrangeMoneyGateway)->initiate($payment);

    expect($response->getStatusCode())->toBe(502);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Failed);
});

it('marks a matching payment completed via the notify webhook on SUCCESS', function () {
    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::OrangeMoney,
        'status' => PaymentStatus::Pending,
        'provider_reference' => 'pay-token-xyz',
    ]);

    $response = $this->postJson(route('payments.orange-money.notify'), [
        'pay_token' => 'pay-token-xyz',
        'status' => 'SUCCESS',
    ]);

    $response->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Completed);
});

it('marks a matching payment failed via the notify webhook on FAILED', function () {
    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::OrangeMoney,
        'status' => PaymentStatus::Pending,
        'provider_reference' => 'pay-token-fail',
    ]);

    $response = $this->postJson(route('payments.orange-money.notify'), [
        'pay_token' => 'pay-token-fail',
        'status' => 'FAILED',
    ]);

    $response->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Failed);
});

it('handles a webhook payload with no matching provider_reference gracefully', function () {
    $countBefore = Payment::count();

    $response = $this->postJson(route('payments.orange-money.notify'), [
        'pay_token' => 'does-not-exist',
        'status' => 'SUCCESS',
    ]);

    $response->assertStatus(404);

    expect(Payment::count())->toBe($countBefore);
});

it('handles a malformed webhook payload gracefully without crashing', function () {
    $response = $this->postJson(route('payments.orange-money.notify'), [
        'foo' => 'bar',
    ]);

    $response->assertStatus(422);
});
