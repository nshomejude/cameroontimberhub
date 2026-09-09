<?php

use App\Actions\ApiKeys\ApproveApiKeyIssuance;
use App\Actions\ApiKeys\RequestApiKeyIssuance;
use App\Enums\CompanyUserRole;
use App\Models\ApiKeyMeta;
use App\Models\Company;
use App\Models\User;
use App\Services\TwoFactorStepUp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    RequestFacade::instance()->setLaravelSession($this->app['session']->driver());

    // These two throwaway routes exist only so this test can drive the real
    // request pipeline (auth:sanctum -> ability check / rate limiter)
    // without touching any production /api/v1 controller.
    Route::middleware(['auth:sanctum', \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class.':products:read'])
        ->get('/__test/api-key/products', fn () => response()->json(['ok' => true]));

    Route::middleware(['auth:sanctum', 'throttle:api-key'])
        ->get('/__test/api-key/pinged', fn () => response()->json(['ok' => true]));
});

/** @return array{token: string, meta: ApiKeyMeta} */
function issueScopedApiKey(array $abilities): array
{
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $company = Company::factory()->create();
    $owner = User::factory()->create();
    $company->users()->attach($owner->id, ['role' => CompanyUserRole::Owner, 'is_primary' => true]);

    $result = app(RequestApiKeyIssuance::class)->execute($company, 'Test key', $abilities, $requester);

    app(TwoFactorStepUp::class)->markVerified(RequestFacade::instance());

    $approval = app(ApproveApiKeyIssuance::class)->execute($result['request'], $approver, $result['invite_token'], RequestFacade::instance());

    return ['token' => $approval['plain_text_token'], 'meta' => $approval['meta']];
}

// Laravel's auth guards cache the resolved user on the guard instance, which
// otherwise survives across the multiple simulated requests one test makes
// with $this->getJson() — a real browser/client would never reuse a guard
// like that, so each Bearer swap below forces a fresh resolution.
function forgetAuthGuardCache(): void
{
    app('auth')->forgetGuards();
}

it('allows a request against /api/v1 using an issued key only for abilities it was granted', function () {
    $allowed = issueScopedApiKey(['products:read']);
    $denied = issueScopedApiKey(['rfqs:write']);

    $this->withHeader('Authorization', 'Bearer '.$allowed['token'])
        ->getJson('/__test/api-key/products')
        ->assertOk();

    forgetAuthGuardCache();

    $this->withHeader('Authorization', 'Bearer '.$denied['token'])
        ->getJson('/__test/api-key/products')
        ->assertForbidden();
});

it('enforces the per-key rate limit independently for two different keys', function () {
    Cache::flush();

    $keyA = issueScopedApiKey(['products:read']);
    $keyB = issueScopedApiKey(['products:read']);

    // Neither company has an active subscription, so both keys are issued
    // at the 'basic' tier (App\Actions\ApiKeys\ApproveApiKeyIssuance falls
    // back to 'basic' with no active Plan) — 30/minute (App\Providers\AppServiceProvider).
    // Drive key A to its limit; key B must remain completely unaffected.
    for ($i = 0; $i < 30; $i++) {
        forgetAuthGuardCache();

        $this->withHeader('Authorization', 'Bearer '.$keyA['token'])
            ->getJson('/__test/api-key/pinged')
            ->assertOk();
    }

    forgetAuthGuardCache();

    $this->withHeader('Authorization', 'Bearer '.$keyA['token'])
        ->getJson('/__test/api-key/pinged')
        ->assertStatus(429);

    forgetAuthGuardCache();

    $this->withHeader('Authorization', 'Bearer '.$keyB['token'])
        ->getJson('/__test/api-key/pinged')
        ->assertOk();
});
