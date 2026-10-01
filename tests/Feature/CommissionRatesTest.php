<?php

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Filament\Pages\CommissionReport;
use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\Commission\CommissionCalculator;
use App\Services\OrderService;
use App\Services\QuoteService;
use Database\Seeders\CommissionRuleSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
 * PRICING_SPEC §15 marketplace commission, end to end: the seeded rates,
 * tier/plan mapping, USD and XAF caps, seeder/migration idempotency, the
 * charge-on-confirm flow, cancellation, dispute credit,
 * the supplier API disclosure and the admin report.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlanSeeder::class);
    Mail::fake();
});

/* ------------------------------------------------------------------ helpers */

/** A supplier on the real seeded plan $slug (null = no plan at all). */
function ratesSupplier(?string $slug, string $countryCode = 'CM'): Company
{
    $company = Company::factory()->publiclyVisible()->create(['country_code' => $countryCode]);

    // CompanyObserver puts every new company on `free`; override (or clear) it.
    $company->forceFill(['plan_id' => $slug ? Plan::where('slug', $slug)->value('id') : null])->save();
    $company->subscriptions()->delete();

    return $company->refresh();
}

/**
 * A real order from $supplier via the accept path, subtotal = $quantity × $unitPrice,
 * confirmed by the supplier (which is when commission is charged) unless $confirm is false.
 */
function ratesOrder(Company $supplier, string $currency, float $unitPrice, int $quantity = 100, string $destination = 'CM', bool $confirm = true): Order
{
    $rfq = Rfq::factory()->approved()->create(['buyer_country_code' => $destination, 'destination_country_code' => $destination]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => $quantity, 'unit' => 'm3']);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'status' => 'sent', 'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'rfq_company_id' => $routing->getKey(),
        'currency' => $currency,
    ]);

    $quote->items()->create([
        'description' => 'Sawn timber', 'quantity' => $quantity, 'unit' => 'm3',
        'unit_price' => $unitPrice, 'line_total' => Quote::lineTotal($quantity, $unitPrice),
    ]);
    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->accept($quote->fresh());
    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    if ($confirm) {
        app(OrderService::class)->confirm($order);
    }

    return $order->refresh();
}

/* ------------------------------------------------------------------ seeder */

it('seeds the §15 rates as fractions with a $5,000 USD cap, mapped to real plan slugs', function () {
    $this->seed(CommissionRuleSeeder::class);

    $rules = CommissionRule::all()->keyBy(fn (CommissionRule $r) => $r->segment.'/'.$r->plan_tier);

    expect($rules->keys()->sort()->values()->all())->toBe([
        'export/exporter-business', 'export/exporter-professional', 'sell/free', 'sell/professional',
    ]);

    expect($rules['sell/free']->only(['domestic_rate', 'international_rate', 'cap_percent', 'cap_amount', 'cap_currency', 'is_active']))
        ->toBe(['domestic_rate' => '0.0300', 'international_rate' => '0.0500', 'cap_percent' => '0.0500', 'cap_amount' => '5000.00', 'cap_currency' => 'USD', 'is_active' => true]);
    expect($rules['sell/professional']->only(['domestic_rate', 'international_rate', 'cap_percent']))
        ->toBe(['domestic_rate' => '0.0250', 'international_rate' => '0.0400', 'cap_percent' => '0.0400']);
    expect($rules['export/exporter-professional']->only(['domestic_rate', 'international_rate', 'cap_percent']))
        ->toBe(['domestic_rate' => '0.0250', 'international_rate' => '0.0400', 'cap_percent' => '0.0400']);
    expect($rules['export/exporter-business']->only(['domestic_rate', 'international_rate', 'cap_percent']))
        ->toBe(['domestic_rate' => '0.0200', 'international_rate' => '0.0300', 'cap_percent' => '0.0300']);

    // Enterprise is negotiated: no seeded rule, and no wildcard that would catch it.
    expect(CommissionRule::whereIn('plan_tier', ['enterprise', 'exporter-enterprise'])->exists())->toBeFalse()
        ->and(CommissionRule::whereNull('plan_tier')->exists())->toBeFalse();
});

it('is idempotent and never overwrites an admin-edited rule', function () {
    // An admin already set their own (inactive, so freely editable) free-tier rule.
    $admin = CommissionRule::create([
        'name' => 'Admin promo', 'segment' => 'sell', 'plan_tier' => 'free',
        'domestic_rate' => 0.01, 'international_rate' => 0.01, 'is_active' => false,
    ]);

    $this->seed(CommissionRuleSeeder::class);
    $this->seed(CommissionRuleSeeder::class);

    expect(CommissionRule::count())->toBe(4)
        ->and(CommissionRule::where('segment', 'sell')->where('plan_tier', 'free')->count())->toBe(1)
        ->and($admin->refresh()->name)->toBe('Admin promo')
        ->and($admin->domestic_rate)->toBe('0.0100')
        ->and($admin->is_active)->toBeFalse();
});

it('skips tiers whose plan does not exist', function () {
    Plan::whereIn('slug', ['exporter-professional', 'exporter-business'])->delete();

    expect(CommissionRuleSeeder::insertMissing())->toBe(2)
        ->and(CommissionRule::where('segment', 'export')->exists())->toBeFalse();
});

it('is part of ReferenceDataSeeder, so production gets it', function () {
    expect(file_get_contents(database_path('seeders/ReferenceDataSeeder.php')))
        ->toContain('CommissionRuleSeeder::class');
});

it('data migration inserts missing rules only, and leaves existing ones alone', function () {
    $existing = CommissionRule::create([
        'name' => 'Negotiated pro', 'segment' => 'sell', 'plan_tier' => 'professional',
        'domestic_rate' => 0.015, 'international_rate' => 0.02, 'is_active' => true,
    ]);

    $migration = require database_path('migrations/2026_10_01_170010_seed_default_commission_rules.php');
    $migration->up();
    $migration->up();

    expect(CommissionRule::count())->toBe(4)
        ->and($existing->refresh()->domestic_rate)->toBe('0.0150')
        ->and(CommissionRule::where('plan_tier', 'free')->value('domestic_rate'))->toBe('0.0300');
});

/* ------------------------------------------------------- rate resolution */

it('resolves the rate per plan tier and domestic/international', function (?string $slug, string $destination, ?string $expectedRate, string $expectedAmount) {
    $this->seed(CommissionRuleSeeder::class);
    $supplier = ratesSupplier($slug);

    // USD 10,000 subtotal — well under every cap.
    $order = ratesOrder($supplier, 'USD', 100.00, 100, $destination);

    expect($order->commission_rate)->toBe($expectedRate)
        ->and($order->is_commission_charged)->toBe($expectedRate !== null)
        ->and((string) ($order->commission_amount ?? '0.00'))->toBe($expectedAmount);
})->with([
    'free domestic' => ['free', 'CM', '0.0300', '300.00'],
    'free international' => ['free', 'US', '0.0500', '500.00'],
    'professional domestic' => ['professional', 'CM', '0.0250', '250.00'],
    'professional international' => ['professional', 'FR', '0.0400', '400.00'],
    'exporter-professional international' => ['exporter-professional', 'US', '0.0400', '400.00'],
    'exporter-business domestic' => ['exporter-business', 'CM', '0.0200', '200.00'],
    'exporter-business international' => ['exporter-business', 'US', '0.0300', '300.00'],
    'no plan at all = free / unlisted' => [null, 'US', '0.0500', '500.00'],
    'enterprise = negotiated, none seeded' => ['enterprise', 'CM', null, '0.00'],
]);

/* --------------------------------------------------------------------- caps */

it('caps a USD order at $5,000', function () {
    $this->seed(CommissionRuleSeeder::class);

    // USD 200,000 × 5% = 10,000 → capped at 5,000.
    $order = ratesOrder(ratesSupplier('free'), 'USD', 2000.00, 100, 'US');

    expect($order->commission_amount)->toBe('5000.00');
});

it('caps an XAF order at the $5,000 cap converted at the configured USD→XAF rate', function () {
    $this->seed(CommissionRuleSeeder::class);

    // XAF 200,000,000 × 5% = 10,000,000 → capped at 5,000 × 600 = 3,000,000 XAF.
    $order = ratesOrder(ratesSupplier('free'), 'XAF', 2000000.00, 100, 'US');
    expect($order->commission_amount)->toBe('3000000.00');

    config(['timber.commission.usd_to_xaf' => 650]);
    $order = ratesOrder(ratesSupplier('free'), 'XAF', 2000000.00, 100, 'US');
    expect($order->commission_amount)->toBe('3250000.00');
});

it('does not cap an XAF order below the converted cap, and rounds XAF to whole francs', function () {
    $this->seed(CommissionRuleSeeder::class);

    // XAF 1,234,567 × 2.5% = 30,864.175 → 30,864 (XAF has no minor unit).
    $order = ratesOrder(ratesSupplier('professional'), 'XAF', 1234567.00, 1, 'CM');

    expect($order->commission_amount)->toBe('30864.00');
});

it('applies the percent cap, and skips the fixed cap when no FX rate is configured for the order currency', function () {
    CommissionRule::create([
        'name' => 'steep', 'segment' => 'sell', 'plan_tier' => 'free',
        'domestic_rate' => 0.10, 'international_rate' => 0.10, 'cap_percent' => 0.04,
        'cap_amount' => 5000, 'cap_currency' => 'USD', 'is_active' => true,
    ]);
    config(['timber.commission.usd_to_gbp' => null]);

    // GBP 1,000,000 × 10% = 100,000 → percent cap 4% = 40,000; no GBP rate, so
    // the $5,000 fixed cap cannot be expressed in GBP and is skipped.
    $order = ratesOrder(ratesSupplier('free'), 'GBP', 10000.00, 100, 'CM');
    expect($order->commission_amount)->toBe('40000.00');

    // With a GBP rate configured the fixed cap applies: 5,000 × 0.8 = 4,000.
    config(['timber.commission.usd_to_gbp' => 0.8]);
    $order = ratesOrder(ratesSupplier('free'), 'GBP', 10000.00, 100, 'CM');
    expect($order->commission_amount)->toBe('4000.00');
});

it('keeps a cap with no cap_currency in the order currency (pre-existing behaviour)', function () {
    CommissionRule::create([
        'name' => 'legacy', 'segment' => 'sell', 'plan_tier' => 'free',
        'domestic_rate' => 0.10, 'international_rate' => 0.10, 'cap_amount' => 250, 'is_active' => true,
    ]);

    $order = ratesOrder(ratesSupplier('free'), 'XAF', 100.00, 100, 'CM');

    expect($order->commission_amount)->toBe('250.00');
});

/* ------------------------------------------------- order lifecycle credits */

it('charges nothing on an order cancelled before supplier acceptance', function () {
    $this->seed(CommissionRuleSeeder::class);
    $order = ratesOrder(ratesSupplier('free'), 'USD', 100.00, confirm: false);

    expect($order->is_commission_charged)->toBeFalse();

    app(OrderService::class)->cancel($order, 'Buyer changed plans');

    expect($order->refresh()->is_commission_charged)->toBeFalse()
        ->and($order->commission_credited_amount)->toBe('0.00')
        ->and(app(CommissionCalculator::class)->creditableAmount($order))->toBe('0.00');
});

it('releases the commission when a confirmed order is cancelled before shipping', function () {
    $this->seed(CommissionRuleSeeder::class);
    $order = ratesOrder(ratesSupplier('free'), 'USD', 100.00);

    expect($order->commission_amount)->toBe('300.00');

    app(OrderService::class)->cancel($order, 'Supplier ran out of stock');

    expect($order->refresh()->commission_credited_amount)->toBe('300.00')
        ->and(app(CommissionCalculator::class)->creditableAmount($order))->toBe('0.00');
});

it('credits commission as part of a dispute decision, refusing an over-credit', function () {
    $this->seed(CommissionRuleSeeder::class);
    $order = ratesOrder(ratesSupplier('free'), 'USD', 100.00); // 300.00 charged
    $buyer = User::factory()->create();
    $admin = User::factory()->create();

    $dispute = Dispute::create([
        'order_id' => $order->id, 'category' => DisputeCategory::Quality, 'status' => DisputeStatus::Opened,
        'description' => 'Wrong grade', 'raised_by_user_id' => $buyer->id,
    ]);

    expect(fn () => $dispute->resolve($admin, 'Refund ordered', 500))->toThrow(RuntimeException::class);
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Opened)
        ->and($order->refresh()->commission_credited_amount)->toBe('0.00');

    $dispute->resolve($admin, 'Half refunded', 150);

    expect($dispute->refresh()->status)->toBe(DisputeStatus::Resolved)
        ->and($order->refresh()->commission_credited_amount)->toBe('150.00');
});

it('lets an admin credit commission from the dispute resolve action', function () {
    $this->seed(CommissionRuleSeeder::class);
    $order = ratesOrder(ratesSupplier('free'), 'USD', 100.00);
    $buyer = User::factory()->create();

    $dispute = Dispute::create([
        'order_id' => $order->id, 'category' => DisputeCategory::Quality, 'status' => DisputeStatus::UnderReview,
        'description' => 'Short delivery', 'raised_by_user_id' => $buyer->id,
    ]);

    $admin = User::factory()->create();
    $admin->givePermissionTo('disputes.manage');
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListDisputes::class)
        ->callTableAction('resolve', $dispute, data: ['resolution_notes' => 'Partial refund', 'commission_credit' => 100]);

    expect($order->refresh()->commission_credited_amount)->toBe('100.00')
        ->and($dispute->refresh()->status)->toBe(DisputeStatus::Resolved);
});

/* ------------------------------------------------------------ disclosure */

it('discloses the commission to the supplier on the API order and quote', function () {
    $this->seed(CommissionRuleSeeder::class);
    $supplier = ratesSupplier('professional');
    $user = User::factory()->create();
    $supplier->users()->attach($user);

    $order = ratesOrder($supplier, 'USD', 100.00, 100, 'US');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/orders/'.$order->reference_code)
        ->assertOk()
        ->assertJsonPath('data.commission.is_charged', true)
        ->assertJsonPath('data.commission.rate', '0.0400')
        ->assertJsonPath('data.commission.amount', '400.00')
        ->assertJsonPath('data.commission.net_amount', '400.00')
        ->assertJsonPath('data.commission.currency', 'USD');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/quotes/'.$order->quote->reference_code)
        ->assertOk()
        ->assertJsonPath('data.commission_preview.rate', '0.0400')
        ->assertJsonPath('data.commission_preview.amount', '400.00')
        ->assertJsonPath('data.commission_preview.is_international', true);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/quotes')
        ->assertOk()
        ->assertJsonPath('data.0.commission_preview.amount', '400.00');
});

it('never shows the supplier commission on the buyer order resource', function () {
    $this->seed(CommissionRuleSeeder::class);
    $order = ratesOrder(ratesSupplier('free'), 'USD', 100.00);
    $buyer = User::factory()->create();
    $order->forceFill(['user_id' => $buyer->id])->save();
    $order->rfq->forceFill(['user_id' => $buyer->id])->save();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code)
        ->assertOk()
        ->assertJsonMissingPath('data.commission');
});

it('shows the commission on the buyer quote screen as supplier-paid', function () {
    $this->seed(CommissionRuleSeeder::class);
    $order = ratesOrder(ratesSupplier('free'), 'USD', 100.00, 100, 'US');
    $quote = $order->quote;

    $preview = app(CommissionCalculator::class)->previewForQuote($quote);

    expect($preview['rate'])->toBe('0.0500')->and($preview['amount'])->toBe('500.00')
        ->and(__('messages.rfq_wizard.marketplace_commission', ['rate' => 5]))->toContain('paid by the supplier');
});

/* -------------------------------------------------------------- report */

it('reports commission per currency, never summing XAF and USD', function () {
    $this->seed(CommissionRuleSeeder::class);
    ratesOrder(ratesSupplier('free'), 'USD', 100.00);                 // 300 USD
    ratesOrder(ratesSupplier('professional'), 'XAF', 100000.00, 10); // 1,000,000 × 2.5% = 25,000 XAF
    $cancelled = ratesOrder(ratesSupplier('free'), 'USD', 100.00);
    app(OrderService::class)->cancel($cancelled, 'changed mind');

    $page = new CommissionReport;
    $rows = $page->getRows()->keyBy(fn (array $r) => $r['currency'].'/'.$r['segment']);

    expect($rows['USD/sell']['orders'])->toBe(2)
        ->and($rows['USD/sell']['gmv'])->toBe('10,000.00')
        ->and($rows['USD/sell']['net_commission'])->toBe('300.00')
        ->and($rows['USD/sell']['take_rate'])->toBe('3%')
        ->and($rows['XAF/sell']['net_commission'])->toBe('25,000.00');

    $totals = collect($page->getTotals()['by_currency'])->keyBy('currency');
    expect($totals['USD']['net_commission'])->toBe('300.00')
        ->and($totals['XAF']['net_commission'])->toBe('25,000.00');

    $this->actingAs(staff('finance_officer'))
        ->get('/admin/commission-report')
        ->assertOk()
        ->assertSee('XAF')
        ->assertSee('25,000.00');
});
