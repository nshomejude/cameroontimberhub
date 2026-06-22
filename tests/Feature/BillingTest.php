<?php

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

it('renders the pricing page with active plans', function () {
    $this->get(route('pricing'))
        ->assertOk()
        ->assertSee('Professional')
        ->assertSee('Enterprise');
});

it('assigns a plan keeping one active subscription and gating features', function () {
    $company = Company::factory()->create();
    $service = app(SubscriptionService::class);

    $service->assign($company, Plan::where('slug', 'professional')->first());
    $company->refresh();
    expect($company->plan?->slug)->toBe('professional')
        ->and($company->hasFeature('leads_receive'))->toBeTrue()
        ->and($company->hasFeature('featured'))->toBeFalse();

    $service->assign($company, Plan::where('slug', 'enterprise')->first());
    $company->refresh();
    expect($company->subscriptions()->where('status', 'active')->count())->toBe(1)
        ->and($company->hasFeature('featured'))->toBeTrue();
});

it('shows plan management only to users with plans.manage', function () {
    $super = User::factory()->create();
    $super->assignRole('super_admin');
    $admin = User::factory()->create();
    $admin->assignRole('admin'); // admin lacks plans.manage

    $this->actingAs($super)->get('/admin/plans')->assertOk();
    $this->actingAs($admin)->get('/admin/plans')->assertForbidden();
});

it('keeps the admin company list rendering with billing actions', function () {
    Company::factory()->publiclyVisible()->create();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get('/admin/companies')->assertOk();
});
