<?php

use App\Domain\Commerce\Commands\AssignSubscriptionCommand;
use App\Domain\Commerce\Commands\RecordPaymentCompletionCommand;
use App\Domain\Commerce\Events\PaymentCompleted;
use App\Domain\Commerce\Events\SubscriptionActivated;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Services\SubscriptionService;
use App\Support\Bus\CommandBus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

/*
 * Architecture plan (docs/superpowers/plans/2026-09-09-api-first-ddd-cqrs-event-driven.md),
 * Phase 4 — Commerce & Billing: bring subscription-assignment and
 * payment-completion writes onto the CommandBus/Outbox pattern.
 */

it('AssignSubscriptionCommand produces the same result as SubscriptionService::assign() directly', function () {
    $company = Company::factory()->create();
    $plan = Plan::factory()->create();
    $actor = User::factory()->create();

    $viaService = app(SubscriptionService::class)->assign($company, $plan, $actor, 'via service');

    $company2 = Company::factory()->create();
    $viaCommand = app(CommandBus::class)->dispatch(new AssignSubscriptionCommand(
        companyId: $company2->getKey(),
        planId: $plan->getKey(),
        actingUserId: $actor->getKey(),
        notes: 'via command',
    ));

    expect($viaCommand)->toBeInstanceOf(Subscription::class)
        ->and($viaCommand->status)->toBe($viaService->status)
        ->and($viaCommand->plan_id)->toBe($plan->getKey())
        ->and($viaCommand->company_id)->toBe($company2->getKey())
        ->and($company2->fresh()->plan_id)->toBe($plan->getKey());
});

it('cancels the prior active subscription exactly like the existing flow when assigning via the command', function () {
    $company = Company::factory()->create();
    $planA = Plan::factory()->create();
    $planB = Plan::factory()->create();

    $first = app(SubscriptionService::class)->assign($company, $planA);

    app(CommandBus::class)->dispatch(new AssignSubscriptionCommand(
        companyId: $company->getKey(),
        planId: $planB->getKey(),
    ));

    expect($first->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($company->fresh()->plan_id)->toBe($planB->getKey());
});

it('records a SubscriptionActivated outbox event transactionally when AssignSubscriptionCommand is dispatched', function () {
    $company = Company::factory()->create();
    $plan = Plan::factory()->create();

    $subscription = app(CommandBus::class)->dispatch(new AssignSubscriptionCommand(
        companyId: $company->getKey(),
        planId: $plan->getKey(),
    ));

    $row = OutboxEvent::query()->where('event_type', 'subscription.activated')
        ->where('aggregate_id', (string) $subscription->getKey())->first();

    expect($row)->not->toBeNull()
        ->and($row->payload)->toMatchArray([
            'subscription_id' => $subscription->getKey(),
            'company_id' => $company->getKey(),
            'plan_id' => $plan->getKey(),
        ]);
});

it('RecordPaymentCompletionCommand produces the same result as Payment::markCompleted() directly', function () {
    $paymentA = Payment::factory()->create();
    $paymentA->markCompleted('ref-direct');

    $paymentB = Payment::factory()->create();
    $result = app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand(
        paymentId: $paymentB->getKey(),
        providerReference: 'ref-command',
    ));

    expect($result)->toBeInstanceOf(Payment::class)
        ->and($result->status)->toBe($paymentA->fresh()->status)
        ->and($result->status)->toBe(PaymentStatus::Completed)
        ->and($result->provider_reference)->toBe('ref-command')
        ->and($result->paid_at)->not->toBeNull();
});

it('records a PaymentCompleted outbox event transactionally when RecordPaymentCompletionCommand is dispatched', function () {
    $payment = Payment::factory()->create();

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand(
        paymentId: $payment->getKey(),
        providerReference: 'ref-1',
    ));

    $row = OutboxEvent::query()->where('event_type', 'payment.completed')
        ->where('aggregate_id', (string) $payment->getKey())->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->payload['payment_id'])->toBe($payment->getKey())
        ->and((int) $row->payload['company_id'])->toBe($payment->company_id);
});

it('rolls back the payment.completed outbox row if the surrounding transaction fails', function () {
    $payment2 = Payment::factory()->create();

    try {
        \Illuminate\Support\Facades\DB::transaction(function () use ($payment2) {
            app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand(paymentId: $payment2->getKey()));

            throw new RuntimeException('simulated failure after command dispatch, before outer commit');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($payment2->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(OutboxEvent::query()->where('event_type', 'payment.completed')
            ->where('aggregate_id', (string) $payment2->getKey())->exists())->toBeFalse();
});

it('RelayOutboxEventsJob relays subscription.activated and payment.completed to their domain event classes', function () {
    Event::fake([SubscriptionActivated::class, PaymentCompleted::class]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'Subscription', 'aggregate_id' => '9001', 'event_type' => 'subscription.activated',
        'payload' => ['subscription_id' => 9001, 'company_id' => 1, 'plan_id' => 2],
        'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'Payment', 'aggregate_id' => '9002', 'event_type' => 'payment.completed',
        'payload' => ['payment_id' => 9002, 'company_id' => 3, 'provider' => 'stripe', 'amount' => '100.00', 'currency' => 'USD'],
        'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Event::assertDispatched(SubscriptionActivated::class, fn (SubscriptionActivated $e) => $e->subscriptionId === 9001 && $e->companyId === 1 && $e->planId === 2);
    Event::assertDispatched(PaymentCompleted::class, fn (PaymentCompleted $e) => $e->paymentId === 9002 && $e->companyId === 3 && $e->provider === 'stripe');

    expect(OutboxEvent::query()->whereNull('published_at')->count())->toBe(0);
});

it('dispatches webhook deliveries for subscription.activated and payment.completed subscribers', function () {
    Bus::fake();

    $company = Company::factory()->create();

    WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['subscription.activated', 'payment.completed'],
        'secret' => 'secret',
        'is_active' => true,
    ]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'Subscription', 'aggregate_id' => '1', 'event_type' => 'subscription.activated',
        'payload' => ['subscription_id' => 1, 'company_id' => $company->id, 'plan_id' => 1],
        'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'Payment', 'aggregate_id' => '1', 'event_type' => 'payment.completed',
        'payload' => ['payment_id' => 1, 'company_id' => $company->id, 'provider' => 'stripe', 'amount' => '10.00', 'currency' => 'USD'],
        'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Bus::assertDispatched(\App\Jobs\DeliverWebhookJob::class, fn ($job) => $job->eventType === 'subscription.activated');
    Bus::assertDispatched(\App\Jobs\DeliverWebhookJob::class, fn ($job) => $job->eventType === 'payment.completed');
});

it('Stripe webhook handler dispatches RecordPaymentCompletionCommand and produces the same Payment state as before', function () {
    config(['payments.stripe.webhook_secret' => 'whsec_test']);

    $payment = Payment::factory()->create(['provider' => \App\Enums\PaymentProvider::Stripe]);

    $payload = json_encode([
        'id' => 'evt_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_test_1',
            'payment_intent' => 'pi_test_1',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]],
    ]);

    $timestamp = time();
    $signedPayload = "{$timestamp}.{$payload}";
    $signature = hash_hmac('sha256', $signedPayload, 'whsec_test');
    $header = "t={$timestamp},v1={$signature}";

    $response = $this->call('POST', '/payments/stripe/webhook', [], [], [], [
        'HTTP_Stripe-Signature' => $header,
        'CONTENT_TYPE' => 'application/json',
    ], $payload);

    $response->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Completed)
        ->and($payment->provider_reference)->toBe('pi_test_1');

    expect(OutboxEvent::query()->where('event_type', 'payment.completed')
        ->where('aggregate_id', (string) $payment->id)->exists())->toBeTrue();
});
