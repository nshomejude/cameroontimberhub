<?php

use App\Enums\CompanyStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\PlatformKpiSnapshot;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use App\Services\OrderService;
use App\Services\PlatformKpiService;
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
    $this->service = app(PlatformKpiService::class);
});

function kpiContext(?Species $species = null): array
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

function kpiQuote(Rfq $rfq, Company $company, Species $species, float $unitPrice = 200.00): Quote
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

function kpiAwardAndComplete(Quote $quote): Order
{
    app(QuoteService::class)->accept($quote);
    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();
    $order->status = OrderStatus::Completed;
    $order->completed_at = now();
    $order->save();

    return $order->refresh();
}

/* --------------------------------------------------------------- counts */

it('counts total and verified companies from real data', function () {
    Company::factory()->count(2)->create(['status' => CompanyStatus::Verified]);
    Company::factory()->create(['status' => CompanyStatus::Pending]);

    expect($this->service->totalCompanies())->toBe(3)
        ->and($this->service->verifiedCompaniesCount())->toBe(2);
});

it('counts active listings only', function () {
    $company = Company::factory()->create();
    Product::factory()->count(2)->create(['company_id' => $company->id, 'status' => ProductStatus::Active]);
    Product::factory()->create(['company_id' => $company->id, 'status' => ProductStatus::Draft]);

    expect($this->service->activeListingsCount())->toBe(2);
});

/* -------------------------------------------------------------------- GMV */

it('computes gross merchandise value from completed order totals', function () {
    [$rfq, $company, $species] = kpiContext();
    $order = kpiAwardAndComplete(kpiQuote($rfq, $company, $species, 200.00));

    expect($this->service->grossMerchandiseValue())->toBe((float) $order->total_amount);
});

it('returns zero gross merchandise value with no completed orders', function () {
    expect($this->service->grossMerchandiseValue())->toBe(0.0);
});

/* ------------------------------------------------------------- MAC / rates */

it('counts monthly active companies from order and product activity', function () {
    [$rfq, $company, $species] = kpiContext();
    kpiAwardAndComplete(kpiQuote($rfq, $company, $species, 100.00));

    $otherCompany = Company::factory()->create();
    Product::factory()->create(['company_id' => $otherCompany->id, 'created_at' => now()]);

    $staleCompany = Company::factory()->create();
    Product::factory()->create(['company_id' => $staleCompany->id, 'created_at' => now()->subMonths(3)]);

    expect($this->service->monthlyActiveCompanies())->toBe(2);
});

it('computes order fulfillment rate excluding cancelled orders', function () {
    [$rfq1, $company1, $species1] = kpiContext();
    kpiAwardAndComplete(kpiQuote($rfq1, $company1, $species1, 100.00));

    [$rfq2, $company2, $species2] = kpiContext();
    $quote2 = kpiQuote($rfq2, $company2, $species2, 100.00);
    app(QuoteService::class)->accept($quote2);
    $order2 = Order::where('quote_id', $quote2->getKey())->firstOrFail();
    $order2->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);

    [$rfq3, $company3, $species3] = kpiContext();
    app(QuoteService::class)->accept(kpiQuote($rfq3, $company3, $species3, 100.00));

    // 3 orders total, 1 cancelled (excluded) -> 2 eligible, 1 completed = 0.5
    expect($this->service->orderFulfillmentRate())->toBe(0.5);
});

it('returns zero fulfillment rate with no orders at all', function () {
    expect($this->service->orderFulfillmentRate())->toBe(0.0);
});

it('computes average order value across completed orders', function () {
    [$rfq1, $company1, $species1] = kpiContext();
    $order1 = kpiAwardAndComplete(kpiQuote($rfq1, $company1, $species1, 100.00));

    [$rfq2, $company2, $species2] = kpiContext();
    $order2 = kpiAwardAndComplete(kpiQuote($rfq2, $company2, $species2, 300.00));

    $expected = round(((float) $order1->total_amount + (float) $order2->total_amount) / 2, 2);

    expect($this->service->averageOrderValue())->toBe($expected);
});

it('returns zero average order value with no completed orders', function () {
    expect($this->service->averageOrderValue())->toBe(0.0);
});

it('degrades gracefully to a full zero snapshot on an empty database', function () {
    expect($this->service->snapshot())->toBe([
        'total_companies' => 0,
        'verified_companies_count' => 0,
        'active_listings_count' => 0,
        'gross_merchandise_value' => 0.0,
        'monthly_active_companies' => 0,
        'order_fulfillment_rate' => 0.0,
        'average_order_value' => 0.0,
    ]);
});

/* ---------------------------------------------------------------- command */

it('runs the snapshot command idempotently, upserting one row per day', function () {
    [$rfq, $company, $species] = kpiContext();
    kpiAwardAndComplete(kpiQuote($rfq, $company, $species, 250.00));

    Artisan::call('platform:kpi-snapshot');
    Artisan::call('platform:kpi-snapshot');

    expect(PlatformKpiSnapshot::count())->toBe(1);

    $row = PlatformKpiSnapshot::sole();
    expect($row->date->toDateString())->toBe(now()->toDateString())
        ->and($row->metrics['total_companies'])->toBe(1)
        ->and($row->metrics['gross_merchandise_value'])->toBeGreaterThan(0);
});

it('runs the snapshot command without erroring on an empty database', function () {
    Artisan::call('platform:kpi-snapshot');

    expect(PlatformKpiSnapshot::count())->toBe(1);
    expect(PlatformKpiSnapshot::sole()->metrics['total_companies'])->toBe(0);
});
