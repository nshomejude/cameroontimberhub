<?php

use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Models\Company;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\RfqItem;
use App\Models\Species;
use App\Services\MarketIntelligenceService;
use App\Services\OrderService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

/* ------------------------------------------------------------------ helpers
   Named distinctly from other test files' helpers -- Pest loads every test
   file into the same process, so a duplicate global function name would be
   a fatal error. */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    $this->service = app(MarketIntelligenceService::class);
});

function miContext(?Species $species = null): array
{
    $species ??= Species::factory()->create(['common_name' => 'Sapele']);
    $company = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create();
    $rfq->items()->create([
        'species_id' => $species->getKey(),
        'species_text' => $species->common_name,
        'form' => 'sawn',
        'quantity' => 100,
        'unit' => 'm3',
    ]);

    RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    return [$rfq, $company, $species];
}

function miQuote(Rfq $rfq, Company $company, Species $species, float $unitPrice = 200.00): Quote
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
        'species_id' => $species->getKey(),
        'description' => 'Sawn timber',
        'quantity' => 100,
        'unit_price' => $unitPrice,
        'line_total' => Quote::lineTotal(100, $unitPrice),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    return $quote;
}

function miAwardAndComplete(Quote $quote): \App\Models\Order
{
    app(QuoteService::class)->accept($quote);
    $order = \App\Models\Order::where('quote_id', $quote->getKey())->firstOrFail();
    $order->status = OrderStatus::Completed;
    $order->completed_at = now();
    $order->save();

    return $order->refresh();
}

/* -------------------------------------------------------------- price index */

it('computes the price index from real awarded order data', function () {
    [$rfq, $company, $species] = miContext();
    $quote = miQuote($rfq, $company, $species, 200.00);
    miAwardAndComplete($quote);

    $index = $this->service->priceIndexBySpecies();

    expect($index)->toHaveCount(1);
    $row = $index->first();
    expect($row['species_id'])->toBe($species->getKey())
        ->and($row['species_name'])->toBe('Sapele')
        ->and($row['avg_unit_price'])->toBe(200.00)
        ->and($row['sample_size'])->toBe(1);
});

it('averages multiple order lines for the same species and month', function () {
    $species = Species::factory()->create(['common_name' => 'Iroko']);

    [$rfq1, $company1] = miContext($species);
    miAwardAndComplete(miQuote($rfq1, $company1, $species, 100.00));

    [$rfq2, $company2] = miContext($species);
    miAwardAndComplete(miQuote($rfq2, $company2, $species, 300.00));

    $index = $this->service->priceIndexBySpecies();

    expect($index)->toHaveCount(1);
    expect($index->first()['avg_unit_price'])->toBe(200.00)
        ->and($index->first()['sample_size'])->toBe(2);
});

it('returns an empty collection for the price index with no order data', function () {
    expect($this->service->priceIndexBySpecies())->toBeEmpty();
});

/* ------------------------------------------------------------- demand index */

it('computes the demand index from verified RFQ volume', function () {
    miContext();

    $index = $this->service->demandIndex();

    expect($index)->toHaveCount(1);
    $row = $index->first();
    expect($row['rfq_count'])->toBe(1)
        ->and($row['total_quantity'])->toBe(100.00);
});

it('returns an empty collection for the demand index with no RFQ data', function () {
    expect($this->service->demandIndex())->toBeEmpty();
});

/* --------------------------------------------------- supplier performance */

it('computes supplier performance only for companies with a completed order', function () {
    [$rfq, $company, $species] = miContext();
    $company->update(['on_time_delivery_percent' => 92]);
    miAwardAndComplete(miQuote($rfq, $company, $species, 150.00));

    // A second company with an order that is never completed must be excluded.
    [$rfq2, $company2, $species2] = miContext();
    app(QuoteService::class)->accept(miQuote($rfq2, $company2, $species2, 150.00));

    $index = $this->service->supplierPerformanceIndex();

    expect($index)->toHaveCount(1);
    $row = $index->first();
    expect($row['company_id'])->toBe($company->getKey())
        ->and($row['on_time_delivery_percent'])->toBe(92)
        ->and($row['completed_order_count'])->toBe(1)
        ->and($row['average_order_value'])->toBe(15000.00);
});

it('returns an empty collection for supplier performance with no completed orders', function () {
    expect($this->service->supplierPerformanceIndex())->toBeEmpty();
});

/* ---------------------------------------------------------------- command */

it('runs the snapshot command without erroring on an empty database', function () {
    Artisan::call('market-intel:snapshot');

    expect(Artisan::output())->toContain('wrote 0 row(s)');
    expect(\App\Models\MarketIntelligenceSnapshot::count())->toBe(0);
});

it('writes snapshot rows when real data exists', function () {
    [$rfq, $company, $species] = miContext();
    $company->update(['on_time_delivery_percent' => 80]);
    miAwardAndComplete(miQuote($rfq, $company, $species, 250.00));

    Artisan::call('market-intel:snapshot');

    expect(\App\Models\MarketIntelligenceSnapshot::where('index_type', 'price_index')->count())->toBe(1);
    expect(\App\Models\MarketIntelligenceSnapshot::where('index_type', 'demand_index')->count())->toBe(1);
    expect(\App\Models\MarketIntelligenceSnapshot::where('index_type', 'supplier_performance_index')->count())->toBe(1);
});
