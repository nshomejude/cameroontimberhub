<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Notifications\TrialEndedUnpaid;
use App\Services\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/*
 * Billing engine Phase 3, task M6 — opt-in trials (§7.5): 14-day, no
 * pre-authorisation, one per company lifetime, converts by paying before the
 * trial ends else lapses to the segment Free plan.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlanSeeder::class);
});

/** A company with no attached user can never be notified — give it one. */
function withTrialNotifiableUser(Company $company): Company
{
    $company->users()->attach(\App\Models\User::factory()->create()->id, [
        'role' => \App\Enums\CompanyUserRole::Owner->value,
        'is_primary' => true,
    ]);

    return $company;
}

function trialPlan(): Plan
{
    $plan = Plan::where('slug', 'professional')->first();
    $plan->update(['trial_days' => 14]);

    return $plan->fresh();
}

it('starts a Trialing subscription with full plan entitlements', function () {
    $company = Company::factory()->create();
    $plan = trialPlan();

    $sub = app(SubscriptionService::class)->startTrial($company, $plan);

    expect($sub->status)->toBe(SubscriptionStatus::Trialing)
        ->and($sub->trial_ends_at->toDateString())->toBe(now()->addDays(14)->toDateString())
        ->and($sub->payment_id)->toBeNull()
        ->and($company->fresh()->hasFeature('leads_receive'))->toBeTrue();
});

it('refuses a second trial for the same company', function () {
    $company = Company::factory()->create();
    $plan = trialPlan();

    app(SubscriptionService::class)->startTrial($company, $plan);

    expect(fn () => app(SubscriptionService::class)->startTrial($company, $plan))
        ->toThrow(RuntimeException::class);
});

it('refuses a trial for a plan with no trial_days', function () {
    $company = Company::factory()->create();
    $plan = Plan::where('slug', 'professional')->first(); // trial_days defaults to 0

    expect(fn () => app(SubscriptionService::class)->startTrial($company, $plan))
        ->toThrow(RuntimeException::class);
});

it('refuses a trial for a company already on a paid plan', function () {
    $company = Company::factory()->create();
    $plan = trialPlan();

    app(SubscriptionService::class)->assign($company, $plan);

    expect(fn () => app(SubscriptionService::class)->startTrial($company, $plan))
        ->toThrow(RuntimeException::class);
});

it('lapses an unpaid trial to the segment Free plan once trial_ends_at has passed', function () {
    $company = withTrialNotifiableUser(Company::factory()->create());
    $plan = trialPlan();

    Notification::fake();

    $sub = Subscription::factory()->create([
        'company_id' => $company->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->subHour(),
        'renews_at' => now()->subHour(),
        'price_amount' => $plan->price_amount,
        'price_currency' => $plan->price_currency,
    ]);

    Artisan::call('subscriptions:process-renewals');

    $sub->refresh();
    $company->refresh();
    $freePlanId = Plan::where('slug', 'free')->value('id');

    expect($sub->status)->toBe(SubscriptionStatus::Expired)
        ->and($company->plan_id)->toBe($freePlanId)
        ->and($company->hasFeature('leads_receive'))->toBeFalse();

    Notification::assertSentTimes(TrialEndedUnpaid::class, 1);
});

it('converts a trial to Active with a real renews_at when paid before it ends, leaving no parallel active row', function () {
    $company = Company::factory()->create();
    $plan = trialPlan();

    $trial = app(SubscriptionService::class)->startTrial($company, $plan);

    $payment = Payment::factory()->create([
        'company_id' => $company->id,
        'plan_id' => $plan->id,
        'status' => PaymentStatus::Completed,
        'amount' => $plan->price_amount,
        'currency' => $plan->price_currency,
    ]);

    $active = app(SubscriptionService::class)->activateFromPayment($payment);

    expect($active->status)->toBe(SubscriptionStatus::Active)
        ->and($active->renews_at->isFuture())->toBeTrue()
        ->and($trial->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(Subscription::where('company_id', $company->id)->where('status', SubscriptionStatus::Active)->count())->toBe(1)
        ->and(Subscription::where('company_id', $company->id)->whereIn('status', [SubscriptionStatus::Trialing])->count())->toBe(0);
});
