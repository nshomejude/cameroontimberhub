<?php

use App\Contracts\AiProviderContract;
use App\Models\ComplianceAssistantQuery;
use App\Models\ComplianceRule;
use App\Models\RegulatorySource;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\ComplianceAssistantService;

function makeRegulatorySource(array $overrides = []): RegulatorySource
{
    return RegulatorySource::query()->create(array_merge([
        'authority' => 'European Commission',
        'instrument_name' => 'EU FLEGT Regulation',
        'jurisdiction' => 'EU',
        'summary' => 'Requires a FLEGT license for certain timber imports into the EU.',
        'legal_review_status' => 'reviewed',
    ], $overrides));
}

function makeComplianceRule(RegulatorySource $source, array $overrides = []): ComplianceRule
{
    return ComplianceRule::query()->create(array_merge([
        'regulatory_source_id' => $source->id,
        'country_code' => 'FR',
        'product_category' => 'sawn_timber',
        'regulatory_framework' => 'EU FLEGT Licensing',
        'decision_rules' => 'A FLEGT license is required for sapele sawn timber shipments into France.',
        'required_evidence' => ['flegt_license'],
        'is_active' => true,
    ], $overrides));
}

it('answers a matching question grounded in the real retrieved rule and source rows', function () {
    $user = User::factory()->create();
    $source = makeRegulatorySource();
    $rule = makeComplianceRule($source);

    $fakeProvider = Mockery::mock(AiProviderContract::class);
    $fakeProvider->shouldReceive('complete')
        ->once()
        ->withArgs(function (string $system, string $userPrompt) use ($rule) {
            return str_contains($system, 'ONLY the regulatory context')
                && str_contains($system, (string) $rule->id);
        })
        ->andReturn('Yes, a FLEGT license is required. (Rule #'.$rule->id.')');
    $fakeProvider->shouldReceive('isConfigured')->andReturn(true);

    $gateway = Mockery::mock(AiGateway::class)->makePartial();
    $gateway->shouldReceive('isReady')->andReturn(true);
    $gateway->shouldReceive('driver')->andReturn($fakeProvider);
    app()->instance(AiGateway::class, $gateway);

    $result = app(ComplianceAssistantService::class)->ask(
        question: 'Does a shipment of Sapele sawn timber to France need a FLEGT license?',
        countryCode: 'FR',
        productCategory: 'sawn_timber',
        askedBy: $user,
    );

    expect($result['ai_ready'])->toBeTrue()
        ->and($result['rate_limited'])->toBeFalse()
        ->and($result['answer'])->toContain('FLEGT license')
        ->and($result['disclaimer'])->toBe(ComplianceAssistantService::DISCLAIMER);

    $groundingIds = $result['grounding']->pluck('id')->all();
    expect($groundingIds)->toContain($rule->id)
        ->and($groundingIds)->toContain($source->id);

    // Every grounding row returned must be a real model actually retrieved from the DB, not fabricated.
    foreach ($result['grounding'] as $row) {
        expect($row)->toBeInstanceOf(\Illuminate\Database\Eloquent\Model::class);
    }

    $logged = ComplianceAssistantQuery::query()->where('asked_by', $user->id)->first();
    expect($logged)->not->toBeNull()
        ->and($logged->was_grounded)->toBeTrue()
        ->and($logged->ai_was_ready)->toBeTrue()
        ->and($logged->grounding_rule_ids)->toContain($rule->id)
        ->and($logged->grounding_source_ids)->toContain($source->id);
});

it('returns the insufficient-information path when nothing matches', function () {
    $user = User::factory()->create();

    // Unrelated data on file, deliberately not matching the question.
    $source = makeRegulatorySource(['instrument_name' => 'US Lacey Act', 'jurisdiction' => 'US', 'summary' => 'US import declaration requirement.']);
    makeComplianceRule($source, ['country_code' => 'US', 'product_category' => 'plywood', 'regulatory_framework' => 'Lacey Act Declaration', 'decision_rules' => 'Requires a Lacey Act declaration for plywood imports.']);

    $fakeProvider = Mockery::mock(AiProviderContract::class);
    $fakeProvider->shouldReceive('isConfigured')->andReturn(true);
    $fakeProvider->shouldNotReceive('complete');

    $gateway = Mockery::mock(AiGateway::class)->makePartial();
    $gateway->shouldReceive('isReady')->andReturn(true);
    $gateway->shouldReceive('driver')->andReturn($fakeProvider);
    app()->instance(AiGateway::class, $gateway);

    $result = app(ComplianceAssistantService::class)->ask(
        question: 'What obscure zzzqx regulation applies to widgets on Mars?',
        countryCode: 'DE',
        productCategory: 'widgets',
        askedBy: $user,
    );

    expect($result['ai_ready'])->toBeTrue()
        ->and($result['answer'])->toContain('not enough regulatory information');

    $logged = ComplianceAssistantQuery::query()->where('asked_by', $user->id)->first();
    expect($logged->was_grounded)->toBeFalse();
});

it('returns gracefully instead of throwing when the AI provider is not configured', function () {
    $user = User::factory()->create();
    $source = makeRegulatorySource();
    makeComplianceRule($source);

    $gateway = Mockery::mock(AiGateway::class)->makePartial();
    $gateway->shouldReceive('isReady')->andReturn(false);
    $gateway->shouldNotReceive('driver');
    app()->instance(AiGateway::class, $gateway);

    $result = app(ComplianceAssistantService::class)->ask(
        question: 'Does sapele sawn timber to France need a FLEGT license?',
        countryCode: 'FR',
        askedBy: $user,
    );

    expect($result['ai_ready'])->toBeFalse()
        ->and($result['answer'])->toContain('not currently configured');

    $logged = ComplianceAssistantQuery::query()->where('asked_by', $user->id)->first();
    expect($logged)->not->toBeNull()
        ->and($logged->ai_was_ready)->toBeFalse();
});

it('logs every question asked, including rate-limited ones being skipped from double logging', function () {
    $user = User::factory()->create();

    $gateway = Mockery::mock(AiGateway::class)->makePartial();
    $gateway->shouldReceive('isReady')->andReturn(false);
    app()->instance(AiGateway::class, $gateway);

    for ($i = 0; $i < 5; $i++) {
        app(ComplianceAssistantService::class)->ask(question: "Question {$i}", askedBy: $user);
    }

    expect(ComplianceAssistantQuery::query()->where('asked_by', $user->id)->count())->toBe(5);

    $result = app(ComplianceAssistantService::class)->ask(question: 'Question 6', askedBy: $user);

    expect($result['rate_limited'])->toBeTrue();
    // The rate-limited response itself is not logged as a new audit row.
    expect(ComplianceAssistantQuery::query()->where('asked_by', $user->id)->count())->toBe(5);
});
