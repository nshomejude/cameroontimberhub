<?php

use App\Actions\ApiKeys\ApproveApiKeyIssuance;
use App\Actions\ApiKeys\RequestApiKeyIssuance;
use App\Enums\CompanyUserRole;
use App\Models\ApiKeyMeta;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\TwoFactorStepUp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Facades\Route;

/**
 * GAPS.md §7 — the runtime `throttle:api-key` limiter resolves a token's tier
 * with the precedence: active plan tier -> explicit ApiKeyMeta tier -> config
 * default. (Issuance-time resolution is covered by
 * ApiKeyRateLimitTierFromPlanTest.)
 */
beforeEach(function () {
    RequestFacade::instance()->setLaravelSession($this->app['session']->driver());

    Route::middleware(['auth:sanctum', 'throttle:api-key'])
        ->get('/__test/api-key/runtime-ping', fn () => response()->json(['ok' => true]));
});

/** @return array{token: string, meta: ApiKeyMeta} */
function issueRuntimeApiKey(Company $company): array
{
    $requester = User::factory()->create();
    $approver = User::factory()->create();

    $result = app(RequestApiKeyIssuance::class)->execute($company, 'Runtime key', ['products:read'], $requester);
    app(TwoFactorStepUp::class)->markVerified(RequestFacade::instance());
    $approval = app(ApproveApiKeyIssuance::class)->execute($result['request'], $approver, $result['invite_token'], RequestFacade::instance());

    return ['token' => $approval['plain_text_token'], 'meta' => $approval['meta']];
}

function runtimeTierCompanyWithOwner(): Company
{
    $company = Company::factory()->create();
    $owner = User::factory()->create();
    $company->users()->attach($owner->id, ['role' => CompanyUserRole::Owner, 'is_primary' => true]);

    return $company;
}

function forgetRuntimeAuthGuardCache(): void
{
    app('auth')->forgetGuards();
}

function drainApiKeyBudget(string $token, int $expectedOk): void
{
    for ($i = 0; $i < $expectedOk; $i++) {
        forgetRuntimeAuthGuardCache();
        test()->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/__test/api-key/runtime-ping')
            ->assertOk();
    }
}

it('lets the active plan tier win over a lower explicit ApiKeyMeta tier', function () {
    Cache::flush();

    $company = runtimeTierCompanyWithOwner();
    $plan = Plan::factory()->create(['slug' => 'enterprise']); // mapped to 'elevated' => 300/min
    Subscription::factory()->create(['company_id' => $company->id, 'plan_id' => $plan->id]);

    $key = issueRuntimeApiKey($company);
    // Force the stored meta tier DOWN to 'basic' (30/min) — the limiter must ignore it.
    $key['meta']->update(['rate_limit_tier' => 'basic']);

    // 31 successful hits would be impossible at the 'basic' 30/min budget.
    drainApiKeyBudget($key['token'], 31);

    forgetRuntimeAuthGuardCache();
    $this->withHeader('Authorization', 'Bearer '.$key['token'])
        ->getJson('/__test/api-key/runtime-ping')
        ->assertOk();
});

it('falls back to the explicit ApiKeyMeta tier when the company has no active subscription', function () {
    Cache::flush();

    $company = runtimeTierCompanyWithOwner();
    $key = issueRuntimeApiKey($company); // no subscription -> issued at config default 'basic'
    $key['meta']->update(['rate_limit_tier' => 'elevated']); // partner key manually bumped

    // 31 OK hits prove we are on 'elevated' (300), not 'basic' (30).
    drainApiKeyBudget($key['token'], 31);

    forgetRuntimeAuthGuardCache();
    $this->withHeader('Authorization', 'Bearer '.$key['token'])
        ->getJson('/__test/api-key/runtime-ping')
        ->assertOk();
});

it('falls back to the config default tier when there is neither a plan nor a companion meta row', function () {
    Cache::flush();
    expect(config('api.rate_limit_tiers.default'))->toBe('basic');

    $company = runtimeTierCompanyWithOwner();
    $key = issueRuntimeApiKey($company);
    // No resolvable plan AND no ApiKeyMeta row at all (e.g. a bare user-issued
    // mobile token) -> config default 'basic' (30/min), not a silent 'standard'.
    ApiKeyMeta::query()->whereKey($key['meta']->getKey())->delete();

    drainApiKeyBudget($key['token'], 30); // basic budget exhausted

    forgetRuntimeAuthGuardCache();
    $this->withHeader('Authorization', 'Bearer '.$key['token'])
        ->getJson('/__test/api-key/runtime-ping')
        ->assertStatus(429);
});
