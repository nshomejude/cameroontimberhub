<?php

use App\Actions\ApiKeys\ApproveApiKeyIssuance;
use App\Actions\ApiKeys\RequestApiKeyIssuance;
use App\Enums\CompanyUserRole;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TwoFactorStepUp;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * API-First plan Phase 3 follow-up (docs/superpowers/plans/2026-09-09-api-first-ddd-cqrs-event-driven.md,
 * called out in Phase 0 Task 0.4's commit): App\Actions\ApiKeys\ApproveApiKeyIssuance
 * must derive the issued key's rate_limit_tier from the requesting
 * company's active Plan (via config('api.rate_limit_tiers'), see
 * App\Models\Plan::apiRateLimitTier()) instead of hardcoding 'standard'.
 */
beforeEach(function () {
    RequestFacade::instance()->setLaravelSession($this->app['session']->driver());
});

function companyOnPlan(?string $planSlug): Company
{
    $company = Company::factory()->create();
    $owner = User::factory()->create();
    $company->users()->attach($owner->id, ['role' => CompanyUserRole::Owner, 'is_primary' => true]);

    if ($planSlug !== null) {
        $plan = Plan::factory()->create(['slug' => $planSlug]);
        Subscription::factory()->create(['company_id' => $company->id, 'plan_id' => $plan->id]);
    }

    return $company;
}

function approveIssuanceFor(Company $company): array
{
    $requester = User::factory()->create();
    $approver = User::factory()->create();

    $result = app(RequestApiKeyIssuance::class)->execute($company, 'Integration key', ['products:read'], $requester);

    app(TwoFactorStepUp::class)->markVerified(RequestFacade::instance());

    return app(ApproveApiKeyIssuance::class)->execute($result['request'], $approver, $result['invite_token'], RequestFacade::instance());
}

it('issues an elevated-tier key for a company on a plan mapped to elevated', function () {
    $company = companyOnPlan('enterprise');

    $approval = approveIssuanceFor($company);

    expect($approval['meta']->rate_limit_tier)->toBe('elevated');
});

it('issues a basic-tier key for a company on a plan mapped to basic', function () {
    $company = companyOnPlan('free');

    $approval = approveIssuanceFor($company);

    expect($approval['meta']->rate_limit_tier)->toBe('basic');
});

it('issues a basic-tier key (not a silent standard) for a company with no active subscription', function () {
    $company = companyOnPlan(null);

    $approval = approveIssuanceFor($company);

    expect($approval['meta']->rate_limit_tier)->toBe('basic');
});

it('falls back to a cancelled subscription being ignored, giving the basic default tier', function () {
    $company = companyOnPlan(null);
    $plan = Plan::factory()->create(['slug' => 'enterprise-but-cancelled']);
    Subscription::factory()->cancelled()->create(['company_id' => $company->id, 'plan_id' => $plan->id]);

    $approval = approveIssuanceFor($company);

    expect($approval['meta']->rate_limit_tier)->toBe('basic');
});
