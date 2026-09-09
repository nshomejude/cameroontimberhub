<?php

use App\Actions\Ai\ApproveAiApiKeyChange;
use App\Actions\Ai\RequestAiApiKeyChange;
use App\Enums\AiProvider;
use App\Models\AiApiKeyChangeRequest;
use App\Models\AiSetting;
use App\Models\User;
use App\Services\TwoFactorStepUp;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // TwoFactorStepUp reads/writes the session on the bound request, which
    // only exists once a session store is attached — normally done by
    // StartSession middleware during a real HTTP request/response cycle.
    RequestFacade::instance()->setLaravelSession($this->app['session']->driver());
});

function markTwoFactorRecentlyVerified(): void
{
    app(TwoFactorStepUp::class)->markVerified(RequestFacade::instance());
}

it('creates a pending key change request without touching ai_settings, and returns a one-time invite token', function () {
    $requester = User::factory()->create();

    $result = app(RequestAiApiKeyChange::class)->execute(AiProvider::Claude, 'sk-test-123', $requester);

    expect($result['request'])->toBeInstanceOf(AiApiKeyChangeRequest::class)
        ->and($result['request']->status)->toBe('pending')
        ->and($result['request']->requested_by)->toBe($requester->id)
        ->and($result['invite_token'])->toBeString()
        ->and(strlen($result['invite_token']))->toBeGreaterThan(20);

    expect(AiSetting::forProvider(AiProvider::Claude)->hasKey())->toBeFalse();
});

it('applies the new key once a different admin approves with the correct invite token and recent 2FA', function () {
    $requester = User::factory()->create();
    $approver = User::factory()->create();

    $result = app(RequestAiApiKeyChange::class)->execute(AiProvider::Claude, 'sk-real-key', $requester);

    markTwoFactorRecentlyVerified();

    app(ApproveAiApiKeyChange::class)->execute($result['request'], $approver, $result['invite_token'], RequestFacade::instance());

    $setting = AiSetting::forProvider(AiProvider::Claude)->fresh();

    expect($setting->hasKey())->toBeTrue()
        ->and($setting->decryptedApiKey())->toBe('sk-real-key')
        ->and($setting->updated_by)->toBe($approver->id)
        ->and($result['request']->fresh()->status)->toBe('approved');
});

it('rejects approval by the same admin who requested the change', function () {
    $requester = User::factory()->create();
    $result = app(RequestAiApiKeyChange::class)->execute(AiProvider::Claude, 'sk-real-key', $requester);

    markTwoFactorRecentlyVerified();

    expect(fn () => app(ApproveAiApiKeyChange::class)->execute($result['request'], $requester, $result['invite_token'], RequestFacade::instance()))
        ->toThrow(ValidationException::class);

    expect(AiSetting::forProvider(AiProvider::Claude)->hasKey())->toBeFalse();
});

it('rejects approval with an incorrect invite token', function () {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $result = app(RequestAiApiKeyChange::class)->execute(AiProvider::Claude, 'sk-real-key', $requester);

    markTwoFactorRecentlyVerified();

    expect(fn () => app(ApproveAiApiKeyChange::class)->execute($result['request'], $approver, 'wrong-token', RequestFacade::instance()))
        ->toThrow(ValidationException::class);

    expect(AiSetting::forProvider(AiProvider::Claude)->hasKey())->toBeFalse();
});

it('rejects approval without a recent two-factor confirmation', function () {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $result = app(RequestAiApiKeyChange::class)->execute(AiProvider::Claude, 'sk-real-key', $requester);

    // Deliberately NOT calling markTwoFactorRecentlyVerified().
    expect(fn () => app(ApproveAiApiKeyChange::class)->execute($result['request'], $approver, $result['invite_token'], RequestFacade::instance()))
        ->toThrow(ValidationException::class);

    expect(AiSetting::forProvider(AiProvider::Claude)->hasKey())->toBeFalse();
});

it('rejects approving a request that already expired', function () {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $result = app(RequestAiApiKeyChange::class)->execute(AiProvider::Claude, 'sk-real-key', $requester);
    $result['request']->update(['expires_at' => now()->subMinute()]);

    markTwoFactorRecentlyVerified();

    expect(fn () => app(ApproveAiApiKeyChange::class)->execute($result['request']->fresh(), $approver, $result['invite_token'], RequestFacade::instance()))
        ->toThrow(ValidationException::class);
});
