<?php

use App\Contracts\AiProviderContract;
use App\Enums\RfqStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Rfq;
use App\Models\Species;
use App\Models\User;
use App\Services\LeadFlowService;
use App\Services\RfqMatchingService;
use App\Services\RfqTriageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/** Mirrors RfqTest.php's own helper — kept local to avoid cross-file symbol clashes. */
function matchingTestRfq(array $attributes = []): Rfq
{
    return Rfq::create(array_merge([
        'reference_code' => 'RFQ-2026-'.Str::upper(Str::random(5)),
        'buyer_name' => 'Buyer',
        'buyer_email' => 'buyer@acme.test',
        'status' => RfqStatus::Approved->value,
        'visibility' => 'public',
    ], $attributes));
}

function matchingTestCompany(Species $species, array $attributes = []): Company
{
    $plan = Plan::factory()->create(['features' => ['leads_receive' => true]]);
    $company = Company::factory()->publiclyVisible()->create(array_merge([
        'plan_id' => $plan->id,
    ], $attributes));
    $company->species()->attach($species->id, ['form' => 'sawn']);

    return $company;
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('returns the deterministic candidate set, unranked, when the AI provider is unavailable', function () {
    $species = Species::factory()->create();
    $company = matchingTestCompany($species);

    $rfq = matchingTestRfq();
    $rfq->items()->create(['species_id' => $species->id, 'form' => 'sawn', 'quantity' => 10, 'unit' => 'm3']);

    // No AiSetting row exists at all, so AiGateway::isReady() is false.
    $suggestions = app(RfqMatchingService::class)->suggestSuppliers($rfq);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()['company']->id)->toBe($company->id)
        ->and($suggestions->first()['score'])->toBeNull()
        ->and($suggestions->first()['why'])->toBeNull();
});

it('returns AI-ranked suggestions with explanations when the AI call succeeds', function () {
    $species = Species::factory()->create();
    $companyA = matchingTestCompany($species, ['legal_name' => 'Alpha Timber']);
    $companyB = matchingTestCompany($species, ['legal_name' => 'Beta Timber']);

    $rfq = matchingTestRfq();
    $rfq->items()->create(['species_id' => $species->id, 'form' => 'sawn', 'quantity' => 10, 'unit' => 'm3']);

    $fake = new class($companyB) implements AiProviderContract
    {
        public function __construct(private Company $preferred) {}

        public function complete(string $systemPrompt, string $userPrompt, array $options = []): string
        {
            return json_encode([
                ['company_id' => $this->preferred->id, 'score' => 90, 'why' => 'Best capacity match.'],
            ]);
        }

        public function extractStructured(string $documentText, array $schema): array
        {
            return [];
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };

    $gateway = new class($fake) extends \App\Services\Ai\AiGateway
    {
        public function __construct(private AiProviderContract $fake) {}

        public function driver(): AiProviderContract
        {
            return $this->fake;
        }

        public function isReady(): bool
        {
            return true;
        }
    };

    $service = new RfqMatchingService($gateway);
    $suggestions = $service->suggestSuppliers($rfq);

    expect($suggestions)->toHaveCount(2)
        ->and($suggestions->first()['company']->id)->toBe($companyB->id)
        ->and($suggestions->first()['score'])->toBe(90)
        ->and($suggestions->first()['why'])->toBe('Best capacity match.')
        // the candidate the model didn't mention is still present, just unranked
        ->and($suggestions->last()['company']->id)->toBe($companyA->id)
        ->and($suggestions->last()['score'])->toBeNull();
});

it('never lets an AI ranking failure affect real RFQ triage/routing', function () {
    $species = Species::factory()->create();
    $company = matchingTestCompany($species);

    $rfq = matchingTestRfq();
    $rfq->items()->create(['species_id' => $species->id, 'form' => 'sawn', 'quantity' => 10, 'unit' => 'm3']);

    $throwing = new class implements AiProviderContract
    {
        public function complete(string $systemPrompt, string $userPrompt, array $options = []): string
        {
            throw new \RuntimeException('AI provider exploded');
        }

        public function extractStructured(string $documentText, array $schema): array
        {
            throw new \RuntimeException('AI provider exploded');
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };

    $gateway = new class($throwing) extends \App\Services\Ai\AiGateway
    {
        public function __construct(private AiProviderContract $fake) {}

        public function driver(): AiProviderContract
        {
            return $this->fake;
        }

        public function isReady(): bool
        {
            return true;
        }
    };

    // The advisory service degrades gracefully instead of throwing.
    $suggestions = (new RfqMatchingService($gateway))->suggestSuppliers($rfq);
    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()['company']->id)->toBe($company->id)
        ->and($suggestions->first()['score'])->toBeNull();

    // And real routing — which never touches AiGateway at all — still works.
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $routed = app(RfqTriageService::class)->route($rfq, [$company->id], $admin, app(LeadFlowService::class));

    expect($routed)->toBe(1);
});
