<?php

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/*
 * Exploit reproductions from the payments authorization audit (P0-1, P1-1,
 * P1-2). Each test drives the attack an outsider could mount and asserts
 * the payment is left untouched.
 */

function auditPayPalConfig(): void
{
    config([
        'payments.paypal.environment' => 'sandbox',
        'payments.paypal.client_id' => 'test-client-id',
        'payments.paypal.client_secret' => 'test-client-secret',
        'payments.paypal.webhook_id' => 'test-webhook-id',
        'payments.paypal.currency' => 'USD',
    ]);
}

function auditPayPalCapture(string $orderId, string $value, string $currency, ?string $customId): void
{
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-access-token'], 200),
        "*/v2/checkout/orders/{$orderId}/capture" => Http::response([
            'id' => $orderId,
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => ['captures' => [[
                    'id' => 'CAPTURE-'.$orderId,
                    'status' => 'COMPLETED',
                    'amount' => ['currency_code' => $currency, 'value' => $value],
                    'custom_id' => $customId,
                ]]],
            ]],
        ], 200),
    ]);
}

function auditSignedReturn(Payment $payment, array $query = []): string
{
    return URL::temporarySignedRoute('payments.paypal.return', now()->addDay(), ['payment' => $payment->id] + $query);
}

test('P0-1: paypal return refuses an attacker token that is not the payment order', function () {
    auditPayPalConfig();
    // Cheap order A (attacker paid 1.00) is "captured" successfully.
    auditPayPalCapture('ORDER-CHEAP-A', '1.00', 'USD', null);

    $expensive = Payment::factory()->create([
        'provider' => PaymentProvider::PayPal,
        'amount' => 999.00,
        'currency' => 'USD',
        'status' => PaymentStatus::Pending,
        'provider_reference' => 'ORDER-EXPENSIVE-B',
    ]);

    $this->get(auditSignedReturn($expensive).'&token=ORDER-CHEAP-A')->assertStatus(400);

    expect($expensive->fresh()->status)->toBe(PaymentStatus::Pending);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'ORDER-CHEAP-A/capture'));
});

test('P0-1: paypal return does not complete when captured amount differs from payment amount', function () {
    auditPayPalConfig();

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::PayPal,
        'amount' => 999.00,
        'currency' => 'USD',
        'status' => PaymentStatus::Pending,
        'provider_reference' => 'ORDER-B',
    ]);
    auditPayPalCapture('ORDER-B', '1.00', 'USD', (string) $payment->id);

    $this->get(auditSignedReturn($payment).'&token=ORDER-B')->assertStatus(400);

    expect($payment->fresh()->status)->not->toBe(PaymentStatus::Completed);
});

test('P0-1: paypal return does not complete when custom_id is another payment', function () {
    auditPayPalConfig();

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::PayPal,
        'amount' => 50.00,
        'currency' => 'USD',
        'status' => PaymentStatus::Pending,
        'provider_reference' => 'ORDER-B',
    ]);
    auditPayPalCapture('ORDER-B', '50.00', 'USD', (string) ($payment->id + 1000));

    $this->get(auditSignedReturn($payment).'&token=ORDER-B')->assertStatus(400);

    expect($payment->fresh()->status)->not->toBe(PaymentStatus::Completed);
});

test('P0-1: paypal return refuses a payment that is not pending', function () {
    auditPayPalConfig();
    auditPayPalCapture('ORDER-B', '50.00', 'USD', null);

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::PayPal,
        'amount' => 50.00,
        'currency' => 'USD',
        'status' => PaymentStatus::Failed,
        'provider_reference' => 'ORDER-B',
    ]);

    $this->get(auditSignedReturn($payment).'&token=ORDER-B')->assertStatus(400);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/capture'));
});

test('P0-1: paypal return completes a matching capture', function () {
    auditPayPalConfig();

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::PayPal,
        'amount' => 50.00,
        'currency' => 'USD',
        'status' => PaymentStatus::Pending,
        'provider_reference' => 'ORDER-B',
    ]);
    auditPayPalCapture('ORDER-B', '50.00', 'USD', (string) $payment->id);

    $this->get(auditSignedReturn($payment).'&token=ORDER-B&PayerID=XYZ')->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed);
});

test('P0-1: paypal webhook does not complete on an amount mismatch or a mere order approval', function () {
    auditPayPalConfig();
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-access-token'], 200),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
    ]);

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::PayPal,
        'amount' => 999.00,
        'currency' => 'USD',
        'status' => PaymentStatus::Pending,
        'provider_reference' => 'ORDER-B',
    ]);

    $this->postJson(route('payments.paypal.webhook'), [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => 'CAPTURE-1',
            'amount' => ['currency_code' => 'USD', 'value' => '1.00'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-B']],
        ],
    ])->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);

    $this->postJson(route('payments.paypal.webhook'), [
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => ['id' => 'ORDER-B'],
    ])->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('P0-1: paypal initiate tags the order with our payment id', function () {
    auditPayPalConfig();
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'fake-access-token'], 200),
        '*/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-NEW',
            'links' => [['href' => 'https://paypal.test/approve', 'rel' => 'approve']],
        ], 201),
    ]);

    $payment = Payment::factory()->create(['provider' => PaymentProvider::PayPal, 'amount' => 10, 'currency' => 'USD']);

    (new \App\Services\Payments\PayPalGateway)->initiate($payment);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/checkout/orders')
        && $r['purchase_units'][0]['custom_id'] === (string) $payment->id
        && str_contains($r['application_context']['return_url'], 'signature='));
});

test('P1-1: unsigned stripe and paypal cancel links cannot fail someone else\'s payment', function () {
    $payment = Payment::factory()->create(['status' => PaymentStatus::Pending]);

    $this->get('/payments/stripe/cancel/'.$payment->id)->assertForbidden();
    $this->get('/payments/paypal/'.$payment->id.'/cancel')->assertForbidden();
    $this->get('/payments/paypal/'.$payment->id.'/return?token=X')->assertForbidden();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('P1-1: signed cancel works for a pending payment and cannot fail a completed one', function () {
    $pending = Payment::factory()->create(['status' => PaymentStatus::Pending]);
    $completed = Payment::factory()->create(['status' => PaymentStatus::Completed]);

    $this->get(URL::temporarySignedRoute('payments.stripe.cancel', now()->addDay(), ['payment' => $pending->id]))->assertRedirect();
    $this->get(URL::temporarySignedRoute('payments.paypal.cancel', now()->addDay(), ['payment' => $completed->id]).'&token=ORDER')->assertOk();

    expect($pending->fresh()->status)->toBe(PaymentStatus::Failed);
    expect($completed->fresh()->status)->toBe(PaymentStatus::Completed);
});

test('P1-1: markFailed is a no-op unless the payment is pending', function () {
    $completed = Payment::factory()->create(['status' => PaymentStatus::Completed]);
    $completed->markFailed();

    expect($completed->fresh()->status)->toBe(PaymentStatus::Completed);
});

test('P1-2: mtn momo submit requires a signed-in member of the paying company', function () {
    config([
        'payments.mtn_momo.subscription_key' => 'sub-key',
        'payments.mtn_momo.api_user' => 'api-user',
        'payments.mtn_momo.api_key' => 'api-key',
    ]);
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'fake-token'], 200),
        '*/collection/v1_0/requesttopay' => Http::response('', 202),
    ]);

    $payment = Payment::factory()->create([
        'provider' => PaymentProvider::MtnMomo,
        'status' => PaymentStatus::Pending,
        'provider_reference' => 'ORIGINAL-REF',
    ]);

    // Guest.
    $this->post(route('payments.mtn-momo.submit', $payment), ['phone' => '677000001'])
        ->assertRedirect(route('login'));

    // Signed-in user of another company.
    $outsider = User::factory()->create();
    Company::factory()->create()->users()->attach($outsider);
    $this->actingAs($outsider)
        ->post(route('payments.mtn-momo.submit', $payment), ['phone' => '677000001'])
        ->assertNotFound();

    Http::assertNothingSent();
    expect($payment->fresh()->provider_reference)->toBe('ORIGINAL-REF');
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

test('P1-2: mtn momo submit refuses a non-pending payment even for its owner', function () {
    config([
        'payments.mtn_momo.subscription_key' => 'sub-key',
        'payments.mtn_momo.api_user' => 'api-user',
        'payments.mtn_momo.api_key' => 'api-key',
    ]);
    Http::fake();

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($user);
    $payment = Payment::factory()->create([
        'company_id' => $company->id,
        'provider' => PaymentProvider::MtnMomo,
        'status' => PaymentStatus::Completed,
    ]);

    $this->actingAs($user)
        ->post(route('payments.mtn-momo.submit', $payment), ['phone' => '677000001'])
        ->assertStatus(409);

    Http::assertNothingSent();
});
