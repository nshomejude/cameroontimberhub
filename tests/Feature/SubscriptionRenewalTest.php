<?php

use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Notifications\SubscriptionLapsedToFree;
use App\Notifications\SubscriptionPastDue;
use App\Notifications\SubscriptionRenewalReminder;
use App\Services\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/*
 * Billing engine Phase 3, task M6 — the pull-model renewal state machine
 * (docs/superpowers/plans/2026-09-10-billing-engine.md §7.5).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlanSeeder::class);
    Notification::fake();
});

function proPlan(): Plan
{
    return Plan::where('slug', 'professional')->first();
}

/**
 * CompanyObserver auto-assigns an Active Free subscription to every new
 * company, and `subscriptions_active_idx` allows only one Active row per
 * company — cancel that auto-created row before installing a test fixture
 * with status Active.
 */
function retireAutoFreeSub(Company $company): void
{
    $company->subscriptions()->where('status', SubscriptionStatus::Active->value)
        ->update(['status' => SubscriptionStatus::Cancelled->value, 'cancelled_at' => now()]);
}

/** A company with no attached user can never be notified — give it one. */
function withNotifiableUser(Company $company): Company
{
    $company->users()->attach(\App\Models\User::factory()->create()->id, [
        'role' => \App\Enums\CompanyUserRole::Owner->value,
        'is_primary' => true,
    ]);

    return $company;
}

it('sends exactly one renewal reminder when renews_at is 6 days out, and none on a second run', function () {
    $company = withNotifiableUser(Company::factory()->create());
    retireAutoFreeSub($company);
    $sub = Subscription::factory()->create([
        'company_id' => $company->id,
        'plan_id' => proPlan()->id,
        'status' => SubscriptionStatus::Active,
        'renews_at' => now()->addDays(6),
        'price_amount' => 50000,
        'price_currency' => 'XAF',
    ]);

    Artisan::call('subscriptions:process-renewals');

    expect($sub->fresh()->renewal_reminded_at)->not->toBeNull();
    Notification::assertSentTimes(SubscriptionRenewalReminder::class, 1);

    Artisan::call('subscriptions:process-renewals');
    Notification::assertSentTimes(SubscriptionRenewalReminder::class, 1);
});

it('does not remind when renews_at is more than 7 days out', function () {
    $company = withNotifiableUser(Company::factory()->create());
    retireAutoFreeSub($company);
    Subscription::factory()->create([
        'company_id' => $company->id,
        'plan_id' => proPlan()->id,
        'status' => SubscriptionStatus::Active,
        'renews_at' => now()->addDays(10),
        'price_amount' => 50000,
        'price_currency' => 'XAF',
    ]);

    Artisan::call('subscriptions:process-renewals');

    Notification::assertNothingSent();
});

it('moves an Active subscription with a past renews_at to PastDue with a 7-day grace and keeps entitlements', function () {
    $company = withNotifiableUser(Company::factory()->create());
    retireAutoFreeSub($company);
    $renewsAt = now()->subDay();
    $sub = Subscription::factory()->create([
        'company_id' => $company->id,
        'plan_id' => proPlan()->id,
        'status' => SubscriptionStatus::Active,
        'renews_at' => $renewsAt,
        'price_amount' => 50000,
        'price_currency' => 'XAF',
    ]);

    Artisan::call('subscriptions:process-renewals');

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::PastDue)
        ->and($sub->grace_until->toDateString())->toBe($renewsAt->copy()->addDays(7)->toDateString());

    Notification::assertSentTimes(SubscriptionPastDue::class, 1);
    expect($company->fresh()->hasFeature('leads_receive'))->toBeTrue();
});

it('lapses a PastDue subscription past grace_until to the segment Free plan', function () {
    $company = withNotifiableUser(Company::factory()->create());
    $sub = Subscription::factory()->create([
        'company_id' => $company->id,
        'plan_id' => proPlan()->id,
        'status' => SubscriptionStatus::PastDue,
        'grace_until' => now()->subDay(),
        'price_amount' => 50000,
        'price_currency' => 'XAF',
    ]);

    Artisan::call('subscriptions:process-renewals');

    $sub->refresh();
    $company->refresh();
    $freePlanId = Plan::where('slug', 'free')->value('id');
    $activeSub = $company->subscriptions()->where('status', SubscriptionStatus::Active->value)->first();

    expect($sub->status)->toBe(SubscriptionStatus::Expired)
        ->and($company->plan_id)->toBe($freePlanId)
        ->and($activeSub->plan_id)->toBe($freePlanId)
        ->and($company->hasFeature('leads_receive'))->toBeFalse();

    Notification::assertSentTimes(SubscriptionLapsedToFree::class, 1);
});

it('ignores a Free-plan subscription with a past renews_at entirely', function () {
    $company = withNotifiableUser(Company::factory()->create());
    retireAutoFreeSub($company);
    $free = Plan::where('slug', 'free')->first();
    Subscription::factory()->create([
        'company_id' => $company->id,
        'plan_id' => $free->id,
        'status' => SubscriptionStatus::Active,
        'renews_at' => now()->subDay(),
        'price_amount' => 0,
        'price_currency' => 'XAF',
    ]);

    Artisan::call('subscriptions:process-renewals');

    Notification::assertNothingSent();
    expect(Subscription::where('company_id', $company->id)->where('status', SubscriptionStatus::Active->value)->count())->toBe(1);
});

it('paying during grace reactivates the company to Active with a fresh renews_at and clears grace', function () {
    $company = withNotifiableUser(Company::factory()->create());
    $plan = proPlan();
    $oldRenewsAt = now()->subDays(3);
    Subscription::factory()->create([
        'company_id' => $company->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::PastDue,
        'renews_at' => $oldRenewsAt,
        'grace_until' => now()->addDays(4),
        'price_amount' => 50000,
        'price_currency' => 'XAF',
    ]);

    $payment = Payment::factory()->create([
        'company_id' => $company->id,
        'plan_id' => $plan->id,
        'status' => \App\Enums\PaymentStatus::Completed,
        'amount' => 50000,
        'currency' => 'XAF',
    ]);

    $sub = app(SubscriptionService::class)->activateFromPayment($payment);

    expect($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->grace_until)->toBeNull()
        ->and($sub->renews_at->isFuture())->toBeTrue()
        ->and($company->fresh()->currentSubscription->id)->toBe($sub->id)
        ->and(Subscription::where('company_id', $company->id)->where('status', SubscriptionStatus::PastDue)->count())->toBe(0);
});

it('idempotency: running process-renewals twice makes no duplicate transitions or emails', function () {
    $company = withNotifiableUser(Company::factory()->create());
    retireAutoFreeSub($company);
    Subscription::factory()->create([
        'company_id' => $company->id,
        'plan_id' => proPlan()->id,
        'status' => SubscriptionStatus::Active,
        'renews_at' => now()->subDay(),
        'price_amount' => 50000,
        'price_currency' => 'XAF',
    ]);

    Artisan::call('subscriptions:process-renewals');
    Artisan::call('subscriptions:process-renewals');

    Notification::assertSentTimes(SubscriptionPastDue::class, 1);
});

it('falls back to the segment Free plan for an Expired subscription too (Company::effectivePlan)', function () {
    $company = withNotifiableUser(Company::factory()->create());
    Subscription::factory()->create([
        'company_id' => $company->id,
        'plan_id' => proPlan()->id,
        'status' => SubscriptionStatus::Expired,
        'ends_at' => now()->subDay(),
    ]);

    expect($company->fresh()->hasFeature('leads_receive'))->toBeFalse()
        ->and($company->fresh()->effectivePlan()?->slug)->toBe('free');
});

it('exposes a read-only /admin/subscriptions resource under billing.view', function () {
    $this->actingAs(staff('content_manager'));
    $this->get('/admin/subscriptions')->assertForbidden();

    $this->actingAs(staff('finance_officer'));
    $this->get('/admin/subscriptions')->assertOk();
});

it('subscriptions:notify-price-changes exits 0', function () {
    expect(Artisan::call('subscriptions:notify-price-changes'))->toBe(0);
});
