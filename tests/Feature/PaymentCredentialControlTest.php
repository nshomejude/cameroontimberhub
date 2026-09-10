<?php

use App\Actions\Payments\ApprovePaymentCredentialChange;
use App\Actions\Payments\RequestPaymentCredentialChange;
use App\Enums\PaymentProvider;
use App\Models\PaymentCredentialChangeRequest;
use App\Models\PaymentSetting;
use App\Models\User;
use App\Services\Payments\MtnMomoGateway;
use App\Services\TwoFactorStepUp;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    RequestFacade::instance()->setLaravelSession($this->app['session']->driver());
});

function markPaymentTwoFactorRecentlyVerified(): void
{
    app(TwoFactorStepUp::class)->markVerified(RequestFacade::instance());
}

$mtnCreds = [
    'subscription_key' => 'sub-key-xyz',
    'api_user' => 'api-user-xyz',
    'api_key' => 'api-key-secret-xyz',
];

it('hides the payment resources from a staff user without payments.manage', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(staff('content_manager'));

    $this->get('/admin/payment-settings')->assertForbidden();
    $this->get('/admin/payment-credential-change-requests')->assertForbidden();
});

it('shows the payment resources to a finance officer', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(staff('finance_officer'));

    $this->get('/admin/payment-settings')->assertOk();
    $this->get('/admin/payment-credential-change-requests')->assertOk();
});

it('stores encrypted proposed credentials and returns a one-time invite token, without touching payment_settings', function () use ($mtnCreds) {
    $requester = User::factory()->create();

    $result = app(RequestPaymentCredentialChange::class)->execute(PaymentProvider::MtnMomo, $mtnCreds, 'production', $requester);

    expect($result['request'])->toBeInstanceOf(PaymentCredentialChangeRequest::class)
        ->and($result['request']->status)->toBe('pending')
        ->and($result['invite_token'])->toBeString()
        ->and(strlen($result['invite_token']))->toBeGreaterThan(20)
        ->and($result['request']->proposed_credentials)->toBe($mtnCreds);

    // Raw column is ciphertext, not the plaintext secret.
    $raw = (string) DB::table('payment_credential_change_requests')->where('id', $result['request']->id)->value('proposed_credentials');
    expect($raw)->not->toContain('api-key-secret-xyz');

    expect(PaymentSetting::resolvedFor(PaymentProvider::MtnMomo))->toBeNull();
});

it('applies the credentials once a different admin approves with the correct token and recent 2FA', function () use ($mtnCreds) {
    $requester = User::factory()->create();
    $approver = User::factory()->create();

    $result = app(RequestPaymentCredentialChange::class)->execute(PaymentProvider::MtnMomo, $mtnCreds, 'production', $requester);

    markPaymentTwoFactorRecentlyVerified();

    app(ApprovePaymentCredentialChange::class)->execute($result['request'], $approver, $result['invite_token'], RequestFacade::instance());

    $setting = PaymentSetting::resolvedFor(PaymentProvider::MtnMomo);

    expect($setting)->not->toBeNull()
        ->and($setting->is_live)->toBeTrue()
        ->and($setting->environment)->toBe('production')
        ->and($setting->credential('api_key'))->toBe('api-key-secret-xyz')
        ->and($setting->updated_by)->toBe($approver->id)
        ->and($result['request']->fresh()->status)->toBe('approved');

    // payment_settings.credentials column is unreadable without the app key.
    $raw = (string) DB::table('payment_settings')->where('id', $setting->id)->value('credentials');
    expect($raw)->not->toContain('api-key-secret-xyz');
});

it('rejects approval by the same admin who requested the change', function () use ($mtnCreds) {
    $requester = User::factory()->create();
    $result = app(RequestPaymentCredentialChange::class)->execute(PaymentProvider::MtnMomo, $mtnCreds, 'production', $requester);

    markPaymentTwoFactorRecentlyVerified();

    expect(fn () => app(ApprovePaymentCredentialChange::class)->execute($result['request'], $requester, $result['invite_token'], RequestFacade::instance()))
        ->toThrow(ValidationException::class);

    expect(PaymentSetting::resolvedFor(PaymentProvider::MtnMomo))->toBeNull();
});

it('rejects approval with an incorrect invite token', function () use ($mtnCreds) {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $result = app(RequestPaymentCredentialChange::class)->execute(PaymentProvider::MtnMomo, $mtnCreds, 'production', $requester);

    markPaymentTwoFactorRecentlyVerified();

    expect(fn () => app(ApprovePaymentCredentialChange::class)->execute($result['request'], $approver, 'wrong-token', RequestFacade::instance()))
        ->toThrow(ValidationException::class);
});

it('rejects approval without a recent two-factor confirmation', function () use ($mtnCreds) {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $result = app(RequestPaymentCredentialChange::class)->execute(PaymentProvider::MtnMomo, $mtnCreds, 'production', $requester);

    expect(fn () => app(ApprovePaymentCredentialChange::class)->execute($result['request'], $approver, $result['invite_token'], RequestFacade::instance()))
        ->toThrow(ValidationException::class);

    expect(PaymentSetting::resolvedFor(PaymentProvider::MtnMomo))->toBeNull();
});

it('rejects approving an expired request', function () use ($mtnCreds) {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $result = app(RequestPaymentCredentialChange::class)->execute(PaymentProvider::MtnMomo, $mtnCreds, 'production', $requester);
    $result['request']->update(['expires_at' => now()->subMinute()]);

    markPaymentTwoFactorRecentlyVerified();

    expect(fn () => app(ApprovePaymentCredentialChange::class)->execute($result['request']->fresh(), $approver, $result['invite_token'], RequestFacade::instance()))
        ->toThrow(ValidationException::class);
});

it('drives MtnMomoGateway::isConfigured() from the live PaymentSetting row', function () use ($mtnCreds) {
    $gateway = app(MtnMomoGateway::class);

    expect($gateway->isConfigured())->toBeFalse();

    $setting = PaymentSetting::forProvider(PaymentProvider::MtnMomo);
    $setting->update(['credentials' => $mtnCreds, 'is_live' => false]);
    expect($gateway->isConfigured())->toBeFalse(); // not live yet

    $setting->update(['is_live' => true]);
    expect($gateway->isConfigured())->toBeTrue();

    $setting->update(['credentials' => ['subscription_key' => 'only-one']]);
    expect($gateway->isConfigured())->toBeFalse(); // missing required keys
});
