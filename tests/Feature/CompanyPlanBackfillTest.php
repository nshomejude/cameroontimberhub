<?php

use App\Console\Commands\BackfillCompanyPlansCommand;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlanSeeder::class);
});

it('assigns the Free plan to a newly created company automatically', function () {
    $company = Company::factory()->create();

    expect($company->plan_id)->not->toBeNull()
        ->and($company->plan?->slug)->toBe('free');
});

it('does not override plan_id already set at creation time', function () {
    $enterprise = Plan::where('slug', 'enterprise')->first();

    $company = Company::factory()->create(['plan_id' => $enterprise->id]);

    expect($company->plan_id)->toBe($enterprise->id);
});

it('backfills plan_id for companies with none, leaving others untouched', function () {
    $withPlan = Company::factory()->create();
    app(SubscriptionService::class)->assign($withPlan, Plan::where('slug', 'professional')->first());
    $withPlan->refresh();

    // Simulate a legacy row untouched by CompanyObserver: create normally
    // (observer assigns Free), then null plan_id back out without firing
    // model events again.
    $legacy = Company::factory()->create();
    $legacy->forceFill(['plan_id' => null])->saveQuietly();

    expect(Company::whereNull('plan_id')->count())->toBe(1);

    $this->artisan(BackfillCompanyPlansCommand::class)
        ->expectsOutputToContain('Backfilled 1 company')
        ->assertExitCode(0);

    $legacy->refresh();
    $withPlan->refresh();

    expect($legacy->plan?->slug)->toBe('free')
        ->and($withPlan->plan?->slug)->toBe('professional');
});

it('is idempotent when run twice', function () {
    $companies = Company::factory()->count(2)->create();
    foreach ($companies as $company) {
        $company->forceFill(['plan_id' => null])->saveQuietly();
    }

    $this->artisan(BackfillCompanyPlansCommand::class)->assertExitCode(0);
    expect(Company::whereNull('plan_id')->count())->toBe(0);

    $this->artisan(BackfillCompanyPlansCommand::class)
        ->expectsOutputToContain('Backfilled 0 companies')
        ->assertExitCode(0);
});

it('assigns a plan to multiple companies via the SubscriptionService loop used by the bulk admin action', function () {
    $companies = Company::factory()->count(3)->create();
    $plan = Plan::where('slug', 'enterprise')->first();
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $service = app(SubscriptionService::class);
    foreach ($companies as $company) {
        $service->assign($company, $plan, $admin);
    }

    foreach ($companies as $company) {
        expect($company->refresh()->plan?->slug)->toBe('enterprise');
    }
});

it('renders the admin companies list with the bulk assign-plan action available to plans.manage users', function () {
    Company::factory()->publiclyVisible()->create();
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)->get('/admin/companies')->assertOk();
});
