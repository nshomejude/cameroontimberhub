<?php

use App\Enums\RfqCurrency;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Services\QuoteService;
use App\Support\Money;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/*
 * Owner decision 2026-10-01: every quote/order LINE is rounded half-up to the
 * currency's real precision — whole francs for XAF/XOF, cents otherwise — so
 * subtotals and totals are sums of payable amounts.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

it('rounds a line to whole francs for XAF/XOF and to cents otherwise', function (float|string $qty, float|string $price, string|RfqCurrency|null $currency, string $expected) {
    expect(Quote::lineTotal($qty, $price, $currency))->toBe($expected);
})->with([
    '2.5 m3 x 10,001 XAF' => ['2.5', '10001', 'XAF', '25003.00'],
    'enum currency' => ['2.5', '10001', RfqCurrency::XAF, '25003.00'],
    'XAF below half rounds down' => ['1.2', '10001', 'XAF', '12001.00'], // 12001.2
    'XOF whole francs' => ['0.5', '3', 'XOF', '2.00'],                 // 1.5 -> 2
    'USD half-up cents' => ['2.5', '10.01', 'USD', '25.03'],             // 25.025
    'EUR no rounding needed' => ['3', '19.99', 'EUR', '59.97'],
    'no currency = cents (legacy)' => ['2.5', '10.01', null, '25.03'],
    'float inputs' => [2.5, 10001.0, 'XAF', '25003.00'],
]);

it('rounds half-up with bcmath, never through floats', function () {
    expect(Money::roundHalfUp('25002.5', 0))->toBe('25003')
        ->and(Money::roundHalfUp('25002.49999999', 0))->toBe('25002')
        ->and(Money::roundHalfUp('0.005', 2))->toBe('0.01')
        ->and(Money::roundHalfUp('-1.5', 0))->toBe('-2')
        ->and(Money::forCurrency('1234.5', 'XAF'))->toBe('1235.00');
});

it('makes the XAF quote and the resulting order sums of whole-franc lines', function () {
    $supplier = Company::factory()->publiclyVisible()->create(['country_code' => 'CM']);
    $rfq = Rfq::factory()->approved()->create(['buyer_country_code' => 'CM', 'destination_country_code' => 'CM']);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 2.5, 'unit' => 'm3']);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'status' => 'sent', 'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'rfq_company_id' => $routing->getKey(),
        'currency' => 'XAF', 'shipping_amount' => null, 'tax_amount' => null,
    ]);

    // Line totals as a pre-change client might have stored them (2dp, not whole francs).
    $quote->items()->create(['description' => 'Sapele', 'quantity' => '2.5', 'unit' => 'm3', 'unit_price' => '10001', 'line_total' => '25002.50']);
    $quote->items()->create(['description' => 'Iroko', 'quantity' => '1.5', 'unit' => 'm3', 'unit_price' => '20001', 'line_total' => '30001.50']);

    app(QuoteService::class)->recalculate($quote);
    $quote->refresh()->load('items');

    // 25,002.5 -> 25,003 ; 30,001.5 -> 30,002
    expect($quote->items->pluck('line_total')->all())->toBe(['25003.00', '30002.00'])
        ->and($quote->subtotal_amount)->toBe('55005.00')
        ->and($quote->total_amount)->toBe('55005.00');

    app(QuoteService::class)->accept($quote);
    $order = Order::where('quote_id', $quote->getKey())->firstOrFail()->load('items');

    expect($order->items->pluck('line_total')->all())->toBe(['25003.00', '30002.00'])
        ->and($order->subtotal_amount)->toBe('55005.00')
        ->and($order->total_amount)->toBe('55005.00');
});

it('keeps cents for a USD quote line', function () {
    $quote = Quote::factory()->create(['currency' => 'USD']);
    $quote->items()->create(['description' => 'x', 'quantity' => '2.5', 'unit' => 'm3', 'unit_price' => '10.01', 'line_total' => '0']);

    app(QuoteService::class)->recalculate($quote);

    expect($quote->refresh()->subtotal_amount)->toBe('25.03');
});
