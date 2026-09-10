<?php

use App\Domain\Commerce\Commands\RecordPaymentCompletionCommand;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Support\Bus\CommandBus;
use Illuminate\Support\Facades\Http;

/*
 * Billing engine M1/M2 (docs/superpowers/plans/2026-09-10-billing-engine.md):
 * a completed plan Payment through ANY gateway must auto-activate a
 * Subscription, exactly once, flipping entitlements and cancelling the prior
 * plan.
 */

function relayOutbox(): void
{
    app(RelayOutboxEventsJob::class)->handle();
}

function payForPlan(Company $company, Plan $plan, array $overrides = []): Payment
{
    return Payment::factory()->create(array_merge([
        'company_id' => $company->id,
        'plan_id' => $plan->id,
        'provider' => PaymentProvider::MtnMomo,
        'status' => PaymentStatus::Pending,
        'amount' => 50000,
        'currency' => 'XAF',
        'provider_reference' => 'ref-'.\Illuminate\Support\Str::random(8),
    ], $overrides));
}

it('activates an Active subscription for the company/plan when a payment completes and the outbox is relayed', function () {
    $company = Company::factory()->create();
    $plan = Plan::factory()->create(['billing_period' => 'monthly', 'price_amount' => 90000, 'features' => ['leads_receive' => true]]);
    $payment = payForPlan($company, $plan, ['amount' => 50000, 'currency' => 'XAF']);

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), $payment->provider_reference));
    relayOutbox();

    $sub = $company->fresh()->currentSubscription;

    expect($sub)->not->toBeNull()
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->plan_id)->toBe($plan->id)
        ->and($sub->payment_id)->toBe($payment->id)
        ->and($sub->renews_at)->not->toBeNull()
        ->and($sub->renews_at->toDateString())->toBe($sub->starts_at->copy()->addMonth()->toDateString())
        // price snapshot is what was PAID, not the plan list price
        ->and((float) $sub->price_amount)->toBe(50000.0)
        ->and($sub->price_currency)->toBe('XAF')
        ->and($company->fresh()->plan_id)->toBe($plan->id);
});

it('adds a year to renews_at for a yearly plan', function () {
    $company = Company::factory()->create();
    $plan = Plan::factory()->create(['billing_period' => 'yearly']);
    $payment = payForPlan($company, $plan);

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), $payment->provider_reference));
    relayOutbox();

    $sub = $company->fresh()->currentSubscription;
    expect($sub->renews_at->toDateString())->toBe($sub->starts_at->copy()->addYear()->toDateString());
});

it('is idempotent — a double webhook relays twice but yields exactly one subscription', function () {
    $company = Company::factory()->create();
    $plan = Plan::factory()->create();
    $payment = payForPlan($company, $plan);

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), $payment->provider_reference));
    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), $payment->provider_reference));
    relayOutbox();
    relayOutbox();

    expect(Subscription::where('company_id', $company->id)->count())->toBe(1)
        ->and(Subscription::where('payment_id', $payment->id)->count())->toBe(1);
});

it('calling activateFromPayment directly twice returns the same subscription', function () {
    $company = Company::factory()->create();
    $plan = Plan::factory()->create();
    $payment = payForPlan($company, $plan, ['status' => PaymentStatus::Completed]);

    $service = app(SubscriptionService::class);
    $first = $service->activateFromPayment($payment);
    $second = $service->activateFromPayment($payment);

    expect($second->id)->toBe($first->id)
        ->and(Subscription::where('company_id', $company->id)->count())->toBe(1);
});

it('flips entitlements — hasFeature is false before activation and true after', function () {
    $company = Company::factory()->create();
    $plan = Plan::factory()->create(['features' => ['leads_receive' => true, 'priority_ranking' => true]]);
    $payment = payForPlan($company, $plan);

    expect($company->fresh()->hasFeature('priority_ranking'))->toBeFalse();

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), $payment->provider_reference));
    relayOutbox();

    expect($company->fresh()->hasFeature('priority_ranking'))->toBeTrue();
});

it('cancels the prior active subscription when a new one activates from payment', function () {
    $company = Company::factory()->create();
    $oldPlan = Plan::factory()->create();
    $newPlan = Plan::factory()->create();

    $oldSub = app(SubscriptionService::class)->assign($company, $oldPlan);
    $payment = payForPlan($company, $newPlan);

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), $payment->provider_reference));
    relayOutbox();

    expect($oldSub->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($company->fresh()->currentSubscription->plan_id)->toBe($newPlan->id)
        ->and($company->fresh()->currentSubscription->status)->toBe(SubscriptionStatus::Active);
});

it('records a SubscriptionActivated outbox event on activation', function () {
    $company = Company::factory()->create();
    $plan = Plan::factory()->create();
    $payment = payForPlan($company, $plan);

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), $payment->provider_reference));
    relayOutbox();

    $sub = $company->fresh()->currentSubscription;

    expect(OutboxEvent::where('event_type', 'subscription.activated')
        ->where('aggregate_id', (string) $sub->id)->exists())->toBeTrue();
});

// ---- Per-gateway webhook → RecordPaymentCompletionCommand ----------------

function mtnConfig(): void
{
    config([
        'payments.mtn_momo.subscription_key' => 'sub-key',
        'payments.mtn_momo.api_user' => 'api-user',
        'payments.mtn_momo.api_key' => 'api-key',
    ]);
}

function orangeConfig(): void
{
    config([
        'payments.orange_money.client_id' => 'cid',
        'payments.orange_money.client_secret' => 'secret',
        'payments.orange_money.merchant_key' => 'mkey',
        'payments.orange_money.currency' => 'XAF',
        'payments.orange_money.environment' => 'sandbox',
    ]);
}

function paypalConfig(): void
{
    config([
        'payments.paypal.environment' => 'sandbox',
        'payments.paypal.client_id' => 'cid',
        'payments.paypal.client_secret' => 'secret',
        'payments.paypal.webhook_id' => 'wh-id',
        'payments.paypal.currency' => 'USD',
    ]);
}

it('MTN MoMo: a SUCCESSFUL webhook completes the payment and writes a payment.completed outbox row', function () {
    mtnConfig();
    $company = Company::factory()->create();
    $plan = Plan::factory()->create();
    $payment = payForPlan($company, $plan, ['provider' => PaymentProvider::MtnMomo, 'provider_reference' => 'mtn-ok']);

    $this->postJson(route('payments.mtn-momo.webhook'), ['referenceId' => 'mtn-ok', 'status' => 'SUCCESSFUL'])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed)
        ->and(OutboxEvent::where('event_type', 'payment.completed')->where('aggregate_id', (string) $payment->id)->exists())->toBeTrue();

    relayOutbox();
    expect($company->fresh()->currentSubscription?->status)->toBe(SubscriptionStatus::Active);
});

it('MTN MoMo: a FAILED webhook fails the payment and never creates a subscription', function () {
    mtnConfig();
    $company = Company::factory()->create();
    $payment = payForPlan($company, Plan::factory()->create(), ['provider' => PaymentProvider::MtnMomo, 'provider_reference' => 'mtn-bad']);

    $this->postJson(route('payments.mtn-momo.webhook'), ['referenceId' => 'mtn-bad', 'status' => 'FAILED'])->assertOk();
    relayOutbox();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and(Subscription::where('company_id', $company->id)->exists())->toBeFalse();
});

it('MTN MoMo: an unconfigured gateway refuses the webhook with no state change', function () {
    config(['payments.mtn_momo.subscription_key' => null, 'payments.mtn_momo.api_user' => null, 'payments.mtn_momo.api_key' => null]);
    $payment = payForPlan(Company::factory()->create(), Plan::factory()->create(), ['provider' => PaymentProvider::MtnMomo, 'provider_reference' => 'mtn-x']);

    $this->postJson(route('payments.mtn-momo.webhook'), ['referenceId' => 'mtn-x', 'status' => 'SUCCESSFUL'])->assertStatus(503);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(OutboxEvent::where('event_type', 'payment.completed')->exists())->toBeFalse();
});

it('Orange Money: a SUCCESS webhook completes the payment and writes a payment.completed outbox row', function () {
    orangeConfig();
    $company = Company::factory()->create();
    $payment = payForPlan($company, Plan::factory()->create(), ['provider' => PaymentProvider::OrangeMoney, 'provider_reference' => 'om-ok']);

    $this->postJson(route('payments.orange-money.notify'), ['pay_token' => 'om-ok', 'status' => 'SUCCESS'])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed)
        ->and(OutboxEvent::where('event_type', 'payment.completed')->where('aggregate_id', (string) $payment->id)->exists())->toBeTrue();

    relayOutbox();
    expect($company->fresh()->currentSubscription?->status)->toBe(SubscriptionStatus::Active);
});

it('Orange Money: a FAILED webhook fails the payment and never creates a subscription', function () {
    orangeConfig();
    $company = Company::factory()->create();
    $payment = payForPlan($company, Plan::factory()->create(), ['provider' => PaymentProvider::OrangeMoney, 'provider_reference' => 'om-bad']);

    $this->postJson(route('payments.orange-money.notify'), ['pay_token' => 'om-bad', 'status' => 'FAILED'])->assertOk();
    relayOutbox();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and(Subscription::where('company_id', $company->id)->exists())->toBeFalse();
});

it('Orange Money: an unconfigured gateway refuses the webhook with no state change', function () {
    config(['payments.orange_money.client_id' => null, 'payments.orange_money.client_secret' => null, 'payments.orange_money.merchant_key' => null]);
    $payment = payForPlan(Company::factory()->create(), Plan::factory()->create(), ['provider' => PaymentProvider::OrangeMoney, 'provider_reference' => 'om-x']);

    $this->postJson(route('payments.orange-money.notify'), ['pay_token' => 'om-x', 'status' => 'SUCCESS'])->assertStatus(503);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(OutboxEvent::where('event_type', 'payment.completed')->exists())->toBeFalse();
});

it('PayPal: a verified capture-completed webhook completes the payment and writes a payment.completed outbox row', function () {
    paypalConfig();
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
    ]);

    $company = Company::factory()->create();
    $payment = payForPlan($company, Plan::factory()->create(), ['provider' => PaymentProvider::PayPal, 'currency' => 'USD', 'provider_reference' => 'PP-ORDER-1']);

    $this->postJson(route('payments.paypal.webhook'), [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => ['id' => 'PP-CAP-1', 'supplementary_data' => ['related_ids' => ['order_id' => 'PP-ORDER-1']]],
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Completed)
        ->and(OutboxEvent::where('event_type', 'payment.completed')->where('aggregate_id', (string) $payment->id)->exists())->toBeTrue();

    relayOutbox();
    expect($company->fresh()->currentSubscription?->status)->toBe(SubscriptionStatus::Active);
});

it('PayPal: a denied capture webhook fails the payment and never creates a subscription', function () {
    paypalConfig();
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
    ]);

    $company = Company::factory()->create();
    $payment = payForPlan($company, Plan::factory()->create(), ['provider' => PaymentProvider::PayPal, 'provider_reference' => 'PP-ORDER-2']);

    $this->postJson(route('payments.paypal.webhook'), [
        'event_type' => 'PAYMENT.CAPTURE.DENIED',
        'resource' => ['id' => 'PP-CAP-2', 'supplementary_data' => ['related_ids' => ['order_id' => 'PP-ORDER-2']]],
    ])->assertOk();
    relayOutbox();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and(Subscription::where('company_id', $company->id)->exists())->toBeFalse();
});

it('PayPal: an unconfigured gateway refuses the webhook with no state change', function () {
    config(['payments.paypal.client_id' => null, 'payments.paypal.client_secret' => null]);
    $payment = payForPlan(Company::factory()->create(), Plan::factory()->create(), ['provider' => PaymentProvider::PayPal, 'provider_reference' => 'PP-ORDER-3']);

    $this->postJson(route('payments.paypal.webhook'), [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => ['id' => 'PP-CAP-3', 'supplementary_data' => ['related_ids' => ['order_id' => 'PP-ORDER-3']]],
    ])->assertStatus(503);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(OutboxEvent::where('event_type', 'payment.completed')->exists())->toBeFalse();
});
