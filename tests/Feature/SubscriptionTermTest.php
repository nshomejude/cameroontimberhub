<?php

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlanSeeder::class);
});

it('snapshots billing_period / price / currency and sets renews_at on assign', function () {
    $company = Company::factory()->create();
    $plan = Plan::where('slug', 'professional')->first();
    $plan->update(['billing_period' => 'monthly', 'price_amount' => 50000, 'price_currency' => 'XAF']);

    $sub = app(SubscriptionService::class)->assign($company, $plan->fresh());

    expect($sub->billing_period)->toBe('monthly')
        ->and((float) $sub->price_amount)->toBe(50000.0)
        ->and($sub->price_currency)->toBe('XAF')
        ->and($sub->renews_at->toDateString())->toBe($sub->starts_at->copy()->addMonth()->toDateString());
});

it('adds a year to renews_at for yearly plans', function () {
    $company = Company::factory()->create();
    $plan = Plan::where('slug', 'enterprise')->first();
    $plan->update(['billing_period' => 'yearly']);

    $sub = app(SubscriptionService::class)->assign($company, $plan->fresh());

    expect($sub->renews_at->toDateString())->toBe($sub->starts_at->copy()->addYear()->toDateString());
});

it('freezes the subscription price against a later plan price change', function () {
    $company = Company::factory()->create();
    $plan = Plan::where('slug', 'professional')->first();
    $plan->update(['price_amount' => 50000, 'price_currency' => 'XAF']);

    $sub = app(SubscriptionService::class)->assign($company, $plan->fresh());

    $plan->update(['price_amount' => 999999]);

    expect((float) $sub->fresh()->price_amount)->toBe(50000.0);
});

it('exposes the Trialing and PastDue statuses with labels and entitlement', function () {
    expect(SubscriptionStatus::from('trialing'))->toBe(SubscriptionStatus::Trialing)
        ->and(SubscriptionStatus::from('past_due'))->toBe(SubscriptionStatus::PastDue)
        ->and(SubscriptionStatus::Trialing->label())->toBe('Trialing')
        ->and(SubscriptionStatus::PastDue->label())->toBe('Past due')
        ->and(SubscriptionStatus::Trialing->isEntitled())->toBeTrue()
        ->and(SubscriptionStatus::PastDue->isEntitled())->toBeTrue()
        ->and(SubscriptionStatus::Cancelled->isEntitled())->toBeFalse()
        ->and(SubscriptionStatus::Expired->isEntitled())->toBeFalse();
});

it('reports inGrace only while past_due and grace_until is in the future', function () {
    $mk = function (array $attrs): Subscription {
        $company = Company::factory()->create(['plan_id' => Plan::where('slug', 'free')->value('id')]);

        return Subscription::factory()->create(array_merge([
            'company_id' => $company->id,
            'plan_id' => Plan::where('slug', 'professional')->value('id'),
        ], $attrs));
    };

    $inGrace = $mk(['status' => SubscriptionStatus::PastDue, 'grace_until' => now()->addDay()]);
    $pastGrace = $mk(['status' => SubscriptionStatus::PastDue, 'grace_until' => now()->subDay()]);
    $active = $mk(['status' => SubscriptionStatus::Active]);

    expect($inGrace->inGrace())->toBeTrue()
        ->and($inGrace->entitled())->toBeTrue()
        ->and($pastGrace->inGrace())->toBeFalse()
        ->and($pastGrace->entitled())->toBeFalse()
        ->and($active->inGrace())->toBeFalse();
});

it('keeps plan features while Active, Trialing, or PastDue-in-grace', function () {
    $pro = Plan::where('slug', 'professional')->first();

    foreach ([
        ['status' => SubscriptionStatus::Active],
        ['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDays(5)],
        ['status' => SubscriptionStatus::PastDue, 'grace_until' => now()->addDays(3)],
    ] as $state) {
        $company = Company::factory()->create(['plan_id' => Plan::where('slug', 'free')->value('id')]);
        Subscription::factory()->create(array_merge([
            'company_id' => $company->id,
            'plan_id' => $pro->id,
        ], $state));

        expect($company->fresh()->hasFeature('leads_receive'))->toBeTrue();
    }
});

it('falls back to the segment Free plan when PastDue past grace or Cancelled', function () {
    $pro = Plan::where('slug', 'professional')->first(); // segment: sell

    $cancelled = Company::factory()->create();
    Subscription::factory()->create([
        'company_id' => $cancelled->id,
        'plan_id' => $pro->id,
        'status' => SubscriptionStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    $pastGrace = Company::factory()->create();
    Subscription::factory()->create([
        'company_id' => $pastGrace->id,
        'plan_id' => $pro->id,
        'status' => SubscriptionStatus::PastDue,
        'grace_until' => now()->subDay(),
    ]);

    expect($cancelled->fresh()->hasFeature('leads_receive'))->toBeFalse()
        ->and($cancelled->fresh()->effectivePlan()?->slug)->toBe('free')
        ->and($pastGrace->fresh()->hasFeature('leads_receive'))->toBeFalse()
        ->and($pastGrace->fresh()->effectivePlan()?->slug)->toBe('free');
});
