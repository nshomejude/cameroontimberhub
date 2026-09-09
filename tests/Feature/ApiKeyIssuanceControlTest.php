<?php

use App\Actions\ApiKeys\ApproveApiKeyIssuance;
use App\Actions\ApiKeys\RequestApiKeyIssuance;
use App\Enums\CompanyUserRole;
use App\Models\ApiKeyIssuanceRequest;
use App\Models\ApiKeyMeta;
use App\Models\Company;
use App\Models\User;
use App\Services\TwoFactorStepUp;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    // TwoFactorStepUp reads/writes the session on the bound request, which
    // only exists once a session store is attached — normally done by
    // StartSession middleware during a real HTTP request/response cycle.
    RequestFacade::instance()->setLaravelSession($this->app['session']->driver());
});

function markApiKeyTwoFactorRecentlyVerified(): void
{
    app(TwoFactorStepUp::class)->markVerified(RequestFacade::instance());
}

function companyWithOwner(): Company
{
    $company = Company::factory()->create();
    $owner = User::factory()->create();
    $company->users()->attach($owner->id, ['role' => CompanyUserRole::Owner, 'is_primary' => true]);

    return $company;
}

it('creates a pending key issuance request without creating a Sanctum token, and returns a one-time invite token', function () {
    $requester = User::factory()->create();
    $company = companyWithOwner();

    $result = app(RequestApiKeyIssuance::class)->execute($company, 'Integration key', ['products:read'], $requester);

    expect($result['request'])->toBeInstanceOf(ApiKeyIssuanceRequest::class)
        ->and($result['request']->status)->toBe('pending')
        ->and($result['request']->requested_by)->toBe($requester->id)
        ->and($result['invite_token'])->toBeString()
        ->and(strlen($result['invite_token']))->toBeGreaterThan(20);

    expect(PersonalAccessToken::count())->toBe(0)
        ->and(ApiKeyMeta::count())->toBe(0);
});

it('creates a working Sanctum token once a different admin approves with the correct invite token and recent 2FA', function () {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $company = companyWithOwner();

    $result = app(RequestApiKeyIssuance::class)->execute($company, 'Integration key', ['products:read'], $requester);

    markApiKeyTwoFactorRecentlyVerified();

    $approval = app(ApproveApiKeyIssuance::class)->execute($result['request'], $approver, $result['invite_token'], RequestFacade::instance());

    expect($approval['plain_text_token'])->toBeString()
        ->and($approval['meta'])->toBeInstanceOf(ApiKeyMeta::class)
        ->and($approval['meta']->company_id)->toBe($company->id)
        ->and($approval['meta']->requested_by)->toBe($requester->id)
        ->and($approval['meta']->approved_by)->toBe($approver->id)
        ->and(PersonalAccessToken::count())->toBe(1)
        ->and($result['request']->fresh()->status)->toBe('approved');

    $token = PersonalAccessToken::first();
    expect($token->abilities)->toBe(['products:read'])
        ->and($token->can('products:read'))->toBeTrue()
        ->and($token->can('rfqs:write'))->toBeFalse();
});

it('rejects approval by the same admin who requested the issuance', function () {
    $requester = User::factory()->create();
    $company = companyWithOwner();
    $result = app(RequestApiKeyIssuance::class)->execute($company, 'Integration key', ['products:read'], $requester);

    markApiKeyTwoFactorRecentlyVerified();

    expect(fn () => app(ApproveApiKeyIssuance::class)->execute($result['request'], $requester, $result['invite_token'], RequestFacade::instance()))
        ->toThrow(ValidationException::class);

    expect(PersonalAccessToken::count())->toBe(0);
});

it('rejects approval with an incorrect invite token', function () {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $company = companyWithOwner();
    $result = app(RequestApiKeyIssuance::class)->execute($company, 'Integration key', ['products:read'], $requester);

    markApiKeyTwoFactorRecentlyVerified();

    expect(fn () => app(ApproveApiKeyIssuance::class)->execute($result['request'], $approver, 'wrong-token', RequestFacade::instance()))
        ->toThrow(ValidationException::class);

    expect(PersonalAccessToken::count())->toBe(0);
});

it('rejects approval without a recent two-factor confirmation', function () {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $company = companyWithOwner();
    $result = app(RequestApiKeyIssuance::class)->execute($company, 'Integration key', ['products:read'], $requester);

    // Deliberately NOT calling markApiKeyTwoFactorRecentlyVerified().
    expect(fn () => app(ApproveApiKeyIssuance::class)->execute($result['request'], $approver, $result['invite_token'], RequestFacade::instance()))
        ->toThrow(ValidationException::class);

    expect(PersonalAccessToken::count())->toBe(0);
});

it('rejects approving a request that already expired', function () {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $company = companyWithOwner();
    $result = app(RequestApiKeyIssuance::class)->execute($company, 'Integration key', ['products:read'], $requester);
    $result['request']->update(['expires_at' => now()->subMinute()]);

    markApiKeyTwoFactorRecentlyVerified();

    expect(fn () => app(ApproveApiKeyIssuance::class)->execute($result['request']->fresh(), $approver, $result['invite_token'], RequestFacade::instance()))
        ->toThrow(ValidationException::class);

    expect(PersonalAccessToken::count())->toBe(0);
});
