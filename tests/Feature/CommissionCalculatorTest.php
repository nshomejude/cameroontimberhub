<?php

use App\Models\Company;
use App\Models\CommissionRule;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\TradeAssuranceAgreement;
use App\Services\Commission\CommissionCalculator;
use App\Services\OrderService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/* ------------------------------------------------------------------ helpers */

/** A supplier company on a given segment/tier/country. */
function commissionSupplier(string $segment, string $planTier, string $countryCode = 'CM'): Company
{
    $plan = Plan::factory()->create(['segment' => $segment, 'slug' => $planTier]);

    return Company::factory()->publiclyVisible()->create([
        'country_code' => $countryCode,
        'plan_id' => $plan->id,
    ]);
}

/** A protected (Trade Assurance) awarded order from that supplier, subtotal $10,000. */
function commissionOrder(Company $supplier, string $buyerCountry = 'CM', float $unitPrice = 100.00, int $quantity = 100): Order
{
    $rfq = Rfq::factory()->approved()->create(['buyer_country_code' => $buyerCountry, 'destination_country_code' => $buyerCountry]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => $quantity, 'unit' => 'm3']);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'status' => 'sent', 'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'rfq_company_id' => $routing->getKey(),
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(), 'description' => 'Sawn timber',
        'quantity' => $quantity, 'unit_price' => $unitPrice, 'line_total' => Quote::lineTotal($quantity, $unitPrice),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->accept($quote);
    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    TradeAssuranceAgreement::createDefaultMilestones($order);

    return $order->refresh();
}

function commissionRule(array $overrides = []): CommissionRule
{
    return CommissionRule::create(array_merge([
        'name' => 'Test rule',
        'segment' => 'sell',
        'plan_tier' => null,
        'domestic_rate' => 0.03,
        'international_rate' => 0.05,
        'cap_amount' => null,
        'cap_percent' => null,
        'is_active' => true,
        'effective_from' => null,
        'effective_until' => null,
    ], $overrides));
}

/* --------------------------------------------------------------- for() */

it('resolves an exact plan_tier rule over an all-tiers rule', function () {
    commissionRule(['plan_tier' => null, 'domestic_rate' => 0.03, 'international_rate' => 0.05]);
    $specific = commissionRule(['name' => 'specific', 'plan_tier' => 'professional', 'domestic_rate' => 0.025, 'international_rate' => 0.04]);

    $resolved = app(CommissionCalculator::class)->for('sell', 'professional');

    expect($resolved->id)->toBe($specific->id);
});

it('resolves an exact segment rule over an all-segments rule', function () {
    commissionRule(['segment' => null, 'name' => 'all-segments']);
    $specific = commissionRule(['segment' => 'sell', 'name' => 'sell-specific']);

    $resolved = app(CommissionCalculator::class)->for('sell', null);

    expect($resolved->id)->toBe($specific->id);
});

it('resolves the correct effective-dated rule', function () {
    commissionRule(['name' => 'old', 'effective_from' => now()->subYear()->toDateString(), 'domestic_rate' => 0.05, 'international_rate' => 0.05]);
    $newer = commissionRule(['name' => 'new', 'effective_from' => now()->subDay()->toDateString(), 'domestic_rate' => 0.02, 'international_rate' => 0.02]);

    $resolved = app(CommissionCalculator::class)->for('sell');

    expect($resolved->id)->toBe($newer->id);
});

/* ----------------------------------------------------------- calculate() */

it('applies the domestic rate for a domestic order', function () {
    commissionRule(['domestic_rate' => 0.03, 'international_rate' => 0.05]);
    $supplier = commissionSupplier('sell', 'professional', 'CM');
    $order = commissionOrder($supplier, 'CM');

    $result = app(CommissionCalculator::class)->calculate($order);

    expect($result['is_international'])->toBeFalse()
        ->and($result['amount'])->toBe('300.00'); // 10,000 * 3%
});

it('applies the international rate for a cross-border order', function () {
    commissionRule(['domestic_rate' => 0.03, 'international_rate' => 0.05]);
    $supplier = commissionSupplier('sell', 'professional', 'CM');
    $order = commissionOrder($supplier, 'US');

    $result = app(CommissionCalculator::class)->calculate($order);

    expect($result['is_international'])->toBeTrue()
        ->and($result['amount'])->toBe('500.00'); // 10,000 * 5%
});

it('bounds the commission by cap_amount', function () {
    commissionRule(['domestic_rate' => 0.10, 'international_rate' => 0.10, 'cap_amount' => 250]);
    $supplier = commissionSupplier('sell', 'professional', 'CM');
    $order = commissionOrder($supplier, 'CM'); // 10,000 * 10% = 1000, capped to 250

    $result = app(CommissionCalculator::class)->calculate($order);

    expect($result['amount'])->toBe('250.00');
});

it('bounds the commission by cap_percent', function () {
    commissionRule(['domestic_rate' => 0.10, 'international_rate' => 0.10, 'cap_percent' => 0.04]);
    $supplier = commissionSupplier('sell', 'professional', 'CM');
    $order = commissionOrder($supplier, 'CM'); // 10,000 * 10% = 1000, capped to 10,000*4% = 400

    $result = app(CommissionCalculator::class)->calculate($order);

    expect($result['amount'])->toBe('400.00');
});

/* ---------------------------------------------------------------- charge() */

it('charges commission on a protected order at award (idempotent, snapshotted)', function () {
    $rule = commissionRule(['domestic_rate' => 0.03, 'international_rate' => 0.05]);
    $supplier = commissionSupplier('sell', 'professional', 'CM');
    $order = commissionOrder($supplier, 'CM');

    // commissionOrder() already created the Trade Assurance agreement, which
    // triggers charge() via TradeAssuranceAgreement::booted(). Assert it fired.
    expect($order->is_commission_charged)->toBeTrue()
        ->and($order->commission_amount)->toBe('300.00')
        ->and($order->commission_rule_id)->toBe($rule->id);

    // Calling charge() again is a no-op.
    app(CommissionCalculator::class)->charge($order);
    expect($order->refresh()->commission_amount)->toBe('300.00');

    // A later rule change never re-rates the already-charged order.
    $rule->update(['is_active' => false]);
    CommissionRule::create([
        'name' => 'new rate', 'segment' => 'sell', 'domestic_rate' => 0.10, 'international_rate' => 0.10, 'is_active' => true,
    ]);

    expect($order->refresh()->commission_amount)->toBe('300.00');
});

it('does not charge commission on a non-protected order', function () {
    commissionRule();
    $rfq = Rfq::factory()->approved()->create(['buyer_country_code' => 'CM', 'destination_country_code' => 'CM']);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3']);
    $supplier = commissionSupplier('sell', 'professional', 'CM');

    $routing = RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'status' => 'sent', 'routed_at' => now()]);
    $quote = Quote::factory()->submitted()->create(['rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'rfq_company_id' => $routing->getKey()]);
    QuoteItem::factory()->create(['quote_id' => $quote->getKey(), 'quantity' => 100, 'unit_price' => 100, 'line_total' => 10000]);
    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->accept($quote);
    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    // No TradeAssuranceAgreement created -> not a protected trade -> never charged.
    app(CommissionCalculator::class)->charge($order);

    expect($order->refresh()->is_commission_charged)->toBeFalse()
        ->and($order->commission_amount)->toBeNull();
});

it('never charges commission on an order cancelled before it becomes a protected trade', function () {
    commissionRule();
    $rfq = Rfq::factory()->approved()->create(['buyer_country_code' => 'CM', 'destination_country_code' => 'CM']);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3']);
    $supplier = commissionSupplier('sell', 'professional', 'CM');

    $routing = RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'status' => 'sent', 'routed_at' => now()]);
    $quote = Quote::factory()->submitted()->create(['rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'rfq_company_id' => $routing->getKey()]);
    QuoteItem::factory()->create(['quote_id' => $quote->getKey(), 'quantity' => 100, 'unit_price' => 100, 'line_total' => 10000]);
    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->decline($quote, 'not needed');

    expect(Order::where('quote_id', $quote->getKey())->exists())->toBeFalse();
});

/* ---------------------------------------------------------------- credit() */

it('credits reduce the room for a future credit, and cannot exceed what was charged', function () {
    commissionRule(['domestic_rate' => 0.03, 'international_rate' => 0.05]);
    $supplier = commissionSupplier('sell', 'professional', 'CM');
    $order = commissionOrder($supplier, 'CM'); // charged 300.00

    app(CommissionCalculator::class)->credit($order, 100, 'partial refund');
    expect($order->refresh()->commission_credited_amount)->toBe('100.00');

    app(CommissionCalculator::class)->credit($order, 200, 'second partial refund');
    expect($order->refresh()->commission_credited_amount)->toBe('300.00');

    expect(fn () => app(CommissionCalculator::class)->credit($order, 1, 'over-credit'))
        ->toThrow(RuntimeException::class);
});

/* --------------------------------------------------------- model immutability */

it('throws when editing the rate of an active commission rule', function () {
    $rule = commissionRule(['is_active' => true]);

    expect(fn () => $rule->update(['domestic_rate' => 0.5]))->toThrow(RuntimeException::class);
});

it('allows superseding an active rule with a new effective_from row without altering it', function () {
    $rule = commissionRule(['is_active' => true, 'domestic_rate' => 0.03]);

    $newer = commissionRule(['name' => 'v2', 'is_active' => true, 'domestic_rate' => 0.10, 'effective_from' => now()->toDateString()]);

    expect($rule->refresh()->domestic_rate)->toBe('0.0300')
        ->and($newer->domestic_rate)->toBe('0.1000');
});
