<?php

use App\Enums\PriceVolumeBand;
use App\Enums\RfqIncoterm;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Order;
use App\Models\PriceObservation;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use App\Models\Company;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

function priceObsContext(): array
{
    $company = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create(['destination_country_code' => 'DE']);
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

function priceObsQuote(Rfq $rfq, Company $company): array
{
    $routing = RfqCompany::firstOrCreate(
        ['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()],
        ['status' => 'sent', 'routed_at' => now()],
    );

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
        'currency' => 'USD',
        'incoterm' => 'FOB',
    ]);

    $species = Species::factory()->create();

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'species_id' => $species->getKey(),
        'description' => 'Sawn timber',
        'quantity' => 100,
        'unit' => 'm3',
        'unit_price' => 185.00,
        'line_total' => Quote::lineTotal(100, 185.00),
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'species_id' => null,
        'description' => 'Unclassified offcuts',
        'quantity' => 5,
        'unit' => 'm3',
        'unit_price' => 40.00,
        'line_total' => Quote::lineTotal(5, 40.00),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    return [$quote, $species];
}

it('records exactly one transacted PriceObservation for the line with a species when an order is awarded', function () {
    [$rfq, $company] = priceObsContext();
    [$quote, $species] = priceObsQuote($rfq, $company);

    app(QuoteService::class)->accept($quote, null);
    app(RelayOutboxEventsJob::class)->handle();

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    $obs = PriceObservation::where('source', 'transacted')->get();

    expect($obs)->toHaveCount(1);

    $row = $obs->first();
    expect($row->species_id)->toBe($species->getKey())
        ->and((float) $row->unit_price)->toBe(185.00)
        ->and((float) $row->quantity)->toBe(100.0)
        ->and($row->unit)->toBe('m3')
        ->and($row->currency->value)->toBe('USD')
        ->and($row->region)->toBe('DE')
        ->and($row->basis->value)->toBe('fob')
        ->and($row->volume_band->value)->toBe('large')
        ->and($row->origin_type)->toBe(Order::class)
        ->and($row->origin_id)->toBe($order->getKey())
        ->and($row->observed_at->toDateString())->toBe($order->awarded_at->toDateString());
});

it('records quoted PriceObservations when a quote is submitted', function () {
    [$rfq, $company] = priceObsContext();

    $routing = RfqCompany::firstOrCreate(
        ['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()],
        ['status' => 'sent', 'routed_at' => now()],
    );

    $quote = Quote::factory()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
        'status' => 'draft',
        'currency' => 'USD',
        'incoterm' => 'CIF',
    ]);

    $species = Species::factory()->create();
    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'species_id' => $species->getKey(),
        'quantity' => 8,
        'unit' => 'm3',
        'unit_price' => 210.00,
        'line_total' => Quote::lineTotal(8, 210.00),
    ]);

    app(QuoteService::class)->submit($quote->fresh(), null);

    $obs = PriceObservation::where('source', 'quoted')->get();

    expect($obs)->toHaveCount(1)
        ->and($obs->first()->species_id)->toBe($species->getKey())
        ->and($obs->first()->basis->value)->toBe('cif')
        ->and($obs->first()->volume_band->value)->toBe('small')
        ->and($obs->first()->origin_type)->toBe(Quote::class);
});

it('records nothing when no line item has a species', function () {
    [$rfq, $company] = priceObsContext();

    $routing = RfqCompany::firstOrCreate(
        ['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()],
        ['status' => 'sent', 'routed_at' => now()],
    );

    $quote = Quote::factory()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
        'status' => 'draft',
    ]);
    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'species_id' => null,
        'quantity' => 20,
        'unit' => 'm3',
        'unit_price' => 100.00,
        'line_total' => Quote::lineTotal(20, 100.00),
    ]);

    app(QuoteService::class)->submit($quote->fresh(), null);

    expect(PriceObservation::count())->toBe(0);
});

it('buckets quantities into volume bands at the boundaries', function () {
    expect(PriceVolumeBand::forQuantity(0.5))->toBe(PriceVolumeBand::Sample)
        ->and(PriceVolumeBand::forQuantity(1.0))->toBe(PriceVolumeBand::Small)
        ->and(PriceVolumeBand::forQuantity(9.99))->toBe(PriceVolumeBand::Small)
        ->and(PriceVolumeBand::forQuantity(10.0))->toBe(PriceVolumeBand::Medium)
        ->and(PriceVolumeBand::forQuantity(49.99))->toBe(PriceVolumeBand::Medium)
        ->and(PriceVolumeBand::forQuantity(50.0))->toBe(PriceVolumeBand::Large)
        ->and(PriceVolumeBand::forQuantity(199.99))->toBe(PriceVolumeBand::Large)
        ->and(PriceVolumeBand::forQuantity(200.0))->toBe(PriceVolumeBand::Bulk);
});

it('has an Other incoterm case that its CHECK constraint accepts and a label that renders', function () {
    $rfq = Rfq::factory()->approved()->create(['incoterm' => 'other']);

    expect($rfq->refresh()->incoterm)->toBe(RfqIncoterm::Other)
        ->and(RfqIncoterm::Other->label())->toBeString()->not->toBe('');
});
