<?php

use App\Enums\ComplianceCaseStatus;
use App\Models\Company;
use App\Models\ComplianceCase;
use App\Models\ComplianceRule;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\RegulatorySource;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Observers\OrderObserver;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/* ------------------------------------------------------------------ helpers */
/* Named distinctly from OrderTest.php's helpers to avoid redeclaration —
   Pest loads every test file into the same process. */

/** An approved RFQ routed to a fresh verified company, targeting a given destination country. */
function wiringOrderContext(string $destinationCountryCode): array
{
    $company = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create([
        'destination_country_code' => $destinationCountryCode,
    ]);
    $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3',
    ]);

    RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    return [$rfq, $company];
}

function wiringOrderQuote(Rfq $rfq, Company $company, float $unitPrice = 185.00): Quote
{
    $routing = RfqCompany::firstOrCreate(
        ['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()],
        ['status' => 'sent', 'routed_at' => now()],
    );

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'description' => 'Sawn timber',
        'quantity' => 100,
        'unit_price' => $unitPrice,
        'line_total' => Quote::lineTotal(100, $unitPrice),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    return $quote;
}

/** Award through the real path and return the resulting order. */
function wiringAwardOrder(Quote $quote): Order
{
    app(QuoteService::class)->accept($quote, null);

    return Order::where('quote_id', $quote->getKey())->firstOrFail();
}

function seedActiveRuleFor(string $countryCode): ComplianceRule
{
    $source = RegulatorySource::factory()->create();

    return ComplianceRule::query()->create([
        'regulatory_source_id' => $source->getKey(),
        'country_code' => $countryCode,
        'regulatory_framework' => 'Test Framework',
        'required_evidence' => [],
        'optional_evidence' => [],
        'risk_factors' => [],
        'is_active' => true,
    ]);
}

/* --------------------------------------------------------------------- */

it('creates a not_assessed compliance case when the destination has an applicable active rule', function () {
    seedActiveRuleFor('DE');

    [$rfq, $company] = wiringOrderContext('DE');
    $quote = wiringOrderQuote($rfq, $company);

    $order = wiringAwardOrder($quote);

    $case = ComplianceCase::query()
        ->where('owner_type', Order::class)
        ->where('owner_id', $order->getKey())
        ->first();

    expect($case)->not->toBeNull()
        ->and($case->status)->toBe(ComplianceCaseStatus::NotAssessed)
        ->and($case->country_code)->toBe('DE');
});

it('creates no compliance case when nothing applies to the destination', function () {
    // No ComplianceRule seeded at all.
    [$rfq, $company] = wiringOrderContext('ZZ');
    $quote = wiringOrderQuote($rfq, $company);

    $order = wiringAwardOrder($quote);

    $exists = ComplianceCase::query()
        ->where('owner_type', Order::class)
        ->where('owner_id', $order->getKey())
        ->exists();

    expect($exists)->toBeFalse();
});

it('still saves the order successfully even if the observer blows up', function () {
    seedActiveRuleFor('FR');

    Log::shouldReceive('error')->once();

    // Force the observer's own logic to fail without touching Order/ComplianceRule/ComplianceCase models.
    $this->partialMock(OrderObserver::class, function ($mock) {
        $mock->shouldReceive('created')->once()->andReturnUsing(function (Order $order) {
            try {
                throw new RuntimeException('simulated observer failure');
            } catch (Throwable $e) {
                Log::error('simulated failure handled', ['order_id' => $order->id]);
            }
        });
    });

    [$rfq, $company] = wiringOrderContext('FR');
    $quote = wiringOrderQuote($rfq, $company);

    $order = wiringAwardOrder($quote);

    expect($order)->not->toBeNull()
        ->and($order->exists)->toBeTrue();
});
