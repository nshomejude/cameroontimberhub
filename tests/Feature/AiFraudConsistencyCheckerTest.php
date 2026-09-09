<?php

use App\Contracts\AiProviderContract;
use App\Enums\FraudSignalType;
use App\Models\Company;
use App\Models\FraudSignal;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\Log;

/* --------------------------------------------------------------- AI reports an inconsistency */

it('creates an AI consistency-check fraud signal when the AI reports an inconsistency', function () {
    $provider = Mockery::mock(AiProviderContract::class);
    $provider->shouldReceive('complete')->once()->andReturn(json_encode([
        'inconsistent' => true,
        'severity' => 'medium',
        'summary' => 'Description contradicts declared company type',
        'reasoning' => 'Claims to be a certified organic plantation but no documents support this.',
    ]));

    $gateway = Mockery::mock(AiGateway::class);
    $gateway->shouldReceive('isReady')->andReturn(true);
    $gateway->shouldReceive('driver')->andReturn($provider);
    $this->app->instance(AiGateway::class, $gateway);

    $company = Company::factory()->create([
        'description' => 'A certified organic plantation exporting premium hardwood.',
    ]);

    expect(FraudSignal::query()
        ->where('subject_type', Company::class)
        ->where('subject_id', $company->id)
        ->where('signal_type', FraudSignalType::AiConsistencyCheck->value)
        ->exists())->toBeTrue();
});

it('does not create a signal when the AI reports no inconsistency', function () {
    $provider = Mockery::mock(AiProviderContract::class);
    $provider->shouldReceive('complete')->once()->andReturn(json_encode([
        'inconsistent' => false,
    ]));

    $gateway = Mockery::mock(AiGateway::class);
    $gateway->shouldReceive('isReady')->andReturn(true);
    $gateway->shouldReceive('driver')->andReturn($provider);
    $this->app->instance(AiGateway::class, $gateway);

    $company = Company::factory()->create();

    expect(FraudSignal::query()
        ->where('subject_type', Company::class)
        ->where('subject_id', $company->id)
        ->where('signal_type', FraudSignalType::AiConsistencyCheck->value)
        ->exists())->toBeFalse();
});

/* --------------------------------------------------------------- AI gateway unconfigured */

it('does not create a signal or error when the AI gateway is unconfigured', function () {
    // Default state: no AiSetting row has a real key, so the real AiGateway
    // (unmocked here) resolves isReady() to false without hitting the
    // container binding above.
    $company = Company::factory()->create();

    expect($company->exists)->toBeTrue()
        ->and(FraudSignal::query()
            ->where('subject_type', Company::class)
            ->where('subject_id', $company->id)
            ->where('signal_type', FraudSignalType::AiConsistencyCheck->value)
            ->exists())->toBeFalse();
});

/* --------------------------------------------------------------- failure injection */

it('still saves the company even when the AI consistency checker throws internally', function () {
    Log::shouldReceive('error')->atLeast()->once();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    $gateway = Mockery::mock(AiGateway::class);
    $gateway->shouldReceive('isReady')->andThrow(new RuntimeException('Simulated AI gateway failure for test.'));
    $this->app->instance(AiGateway::class, $gateway);

    $company = Company::factory()->create();

    expect($company->exists)->toBeTrue()
        ->and($company->wasRecentlyCreated)->toBeTrue();
});
