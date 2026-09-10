<?php

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\MtnMomoGateway;
use Illuminate\Support\Facades\Http;

function fakeMtnMomoConfig(): void
{
    config([
        'payments.mtn_momo.subscription_key' => 'sub-key',
        'payments.mtn_momo.api_user' => 'api-user',
        'payments.mtn_momo.api_key' => 'api-key',
    ]);
}

function mtnCompanyUser(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => 'owner']);

    return [$company, $user];
}

it('is not configured when credentials are missing', function () {
    config([
        'payments.mtn_momo.subscription_key' => null,
        'payments.mtn_momo.api_user' => null,
        'payments.mtn_momo.api_key' => null,
    ]);

    expect((new MtnMomoGateway)->isConfigured())->toBeFalse();
});

it('is configured when all credentials are present', function () {
    config([
        'payments.mtn_momo.subscription_key' => 'sub-key',
        'payments.mtn_momo.api_user' => 'api-user',
        'payments.mtn_momo.api_key' => 'api-key',
    ]);

    expect((new MtnMomoGateway)->isConfigured())->toBeTrue();
});

it('shows the not-configured response when starting checkout without credentials', function () {
    config([
        'payments.mtn_momo.subscription_key' => null,
        'payments.mtn_momo.api_user' => null,
        'payments.mtn_momo.api_key' => null,
    ]);

    [$company, $user] = mtnCompanyUser();
    $plan = Plan::factory()->create();

    $response = $this->actingAs($user)->post(route('payments.checkout', $plan), [
        'provider' => PaymentProvider::MtnMomo->value,
    ]);

    $response->assertStatus(503);
    $response->assertSee("MTN Mobile Money isn't set up yet", false);

    expect(Payment::where('company_id', $company->id)->where('status', PaymentStatus::Completed)->exists())->toBeFalse();
});

it('shows the phone-number form when configured', function () {
    config([
        'payments.mtn_momo.subscription_key' => 'sub-key',
        'payments.mtn_momo.api_user' => 'api-user',
        'payments.mtn_momo.api_key' => 'api-key',
    ]);

    [$company, $user] = mtnCompanyUser();
    $plan = Plan::factory()->create();

    $response = $this->actingAs($user)->post(route('payments.checkout', $plan), [
        'provider' => PaymentProvider::MtnMomo->value,
    ]);

    $response->assertStatus(200);
    $response->assertSee('Pay with MTN Mobile Money');
});

it('submits a request to pay and records the provider reference on success', function () {
    config([
        'payments.mtn_momo.subscription_key' => 'sub-key',
        'payments.mtn_momo.api_user' => 'api-user',
        'payments.mtn_momo.api_key' => 'api-key',
        'payments.mtn_momo.environment' => 'sandbox',
        'payments.mtn_momo.target_environment' => 'mtncameroon',
        'payments.mtn_momo.callback_host' => 'https://example.test',
        'payments.mtn_momo.currency' => 'XAF',
    ]);

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'fake-token'], 200),
        '*/collection/v1_0/requesttopay' => Http::response('', 202),
    ]);

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::MtnMomo,
        'status' => PaymentStatus::Pending,
        'provider_reference' => null,
    ]);

    $response = $this->post(route('payments.mtn-momo.submit', $payment), [
        'phone' => '677123456',
    ]);

    $response->assertStatus(200);
    $response->assertSee('Check your phone');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/collection/token/'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/collection/v1_0/requesttopay')
        && $request->hasHeader('X-Reference-Id')
        && $request->hasHeader('X-Target-Environment', 'mtncameroon')
        && $request->hasHeader('Ocp-Apim-Subscription-Key', 'sub-key')
        && $request['payer']['partyId'] === '677123456');

    $payment->refresh();
    expect($payment->provider_reference)->not->toBeNull();
    expect($payment->status)->toBe(PaymentStatus::Pending);
});

it('marks the payment failed when the request to pay call fails', function () {
    config([
        'payments.mtn_momo.subscription_key' => 'sub-key',
        'payments.mtn_momo.api_user' => 'api-user',
        'payments.mtn_momo.api_key' => 'api-key',
    ]);

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'fake-token'], 200),
        '*/collection/v1_0/requesttopay' => Http::response(['message' => 'bad request'], 400),
    ]);

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::MtnMomo,
        'status' => PaymentStatus::Pending,
        'provider_reference' => null,
    ]);

    $response = $this->post(route('payments.mtn-momo.submit', $payment), [
        'phone' => '677123456',
    ]);

    $response->assertStatus(502);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Failed);
});

it('marks the payment failed gracefully when the token request throws', function () {
    config([
        'payments.mtn_momo.subscription_key' => 'sub-key',
        'payments.mtn_momo.api_user' => 'api-user',
        'payments.mtn_momo.api_key' => 'api-key',
    ]);

    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('connection refused');
    });

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::MtnMomo,
        'status' => PaymentStatus::Pending,
        'provider_reference' => null,
    ]);

    $response = $this->post(route('payments.mtn-momo.submit', $payment), [
        'phone' => '677123456',
    ]);

    $response->assertStatus(502);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Failed);
});

it('marks a matching payment completed via webhook', function () {
    fakeMtnMomoConfig();

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::MtnMomo,
        'status' => PaymentStatus::Pending,
        'provider_reference' => 'ref-123',
    ]);

    $response = $this->postJson(route('payments.mtn-momo.webhook'), [
        'referenceId' => 'ref-123',
        'status' => 'SUCCESSFUL',
    ]);

    $response->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Completed);
});

it('marks a matching payment failed via webhook', function () {
    fakeMtnMomoConfig();

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::MtnMomo,
        'status' => PaymentStatus::Pending,
        'provider_reference' => 'ref-456',
    ]);

    $response = $this->postJson(route('payments.mtn-momo.webhook'), [
        'referenceId' => 'ref-456',
        'status' => 'FAILED',
    ]);

    $response->assertStatus(200);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Failed);
});

it('does not crash and does not create data for an unknown webhook reference', function () {
    fakeMtnMomoConfig();

    $response = $this->postJson(route('payments.mtn-momo.webhook'), [
        'referenceId' => 'does-not-exist',
        'status' => 'SUCCESSFUL',
    ]);

    $response->assertStatus(404);

    expect(Payment::where('provider_reference', 'does-not-exist')->exists())->toBeFalse();
});

it('returns a validation error response for a malformed webhook payload', function () {
    fakeMtnMomoConfig();

    $response = $this->postJson(route('payments.mtn-momo.webhook'), [
        'foo' => 'bar',
    ]);

    $response->assertStatus(422);
});
