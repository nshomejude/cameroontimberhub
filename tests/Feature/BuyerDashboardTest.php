<?php

use App\Enums\CompanyUserRole;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\BuyerDashboard;
use App\Services\OrderService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/* ------------------------------------------------------------------ helpers */
/* Pest loads every test file into one process, so these names are prefixed to
   stay clear of QuoteTest / OrderTest's helpers. */

function dashBuyer(array $attributes = []): User
{
    return User::factory()->create($attributes + ['email' => 'buyer'.uniqid().'@example.com']);
}

/** A verified, approved RFQ owned by $user, with one line item. */
function dashRfq(?User $user = null, array $attributes = []): Rfq
{
    $rfq = Rfq::factory()->approved()->create(array_merge([
        'user_id' => $user?->getKey(),
        'buyer_email' => $user?->email ?? 'guest@example.com',
    ], $attributes));

    $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3',
    ]);

    return $rfq;
}

/** A submitted quote on $rfq: 100 units at $unitPrice. */
function dashQuote(Rfq $rfq, ?Company $company = null, float $unitPrice = 185.00, array $overrides = []): Quote
{
    $company ??= Company::factory()->publiclyVisible()->create();

    $routing = RfqCompany::firstOrCreate(
        ['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()],
        ['status' => 'sent', 'routed_at' => now()],
    );

    $quote = Quote::factory()->submitted()->create(array_merge([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
    ], $overrides));

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'quantity' => 100,
        'unit_price' => $unitPrice,
        'line_total' => Quote::lineTotal(100, $unitPrice),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    return $quote;
}

/** Award a quote through the real service path and return the order. */
function dashAward(Quote $quote): Order
{
    app(QuoteService::class)->accept($quote);

    return app(OrderService::class)->createFromQuote($quote->refresh());
}

/* ------------------------------------------------------------------- access */

it('redirects a guest to login and preserves the intended url', function () {
    $this->get('/account')->assertRedirect(route('login'));

    $this->get('/account/orders')->assertRedirect(route('login'));
    expect(session('url.intended'))->toBe(url('/account/orders'));
});

it('shows the dashboard to a signed-in buyer', function () {
    $buyer = dashBuyer(['name' => 'Bea Buyer']);
    dashRfq($buyer);

    $this->actingAs($buyer)->get('/account')
        ->assertOk()
        ->assertSee('Bea Buyer')
        ->assertSee('Dashboard');
});

it('marks every account page noindex', function () {
    $buyer = dashBuyer();
    dashRfq($buyer);

    foreach (['/account', '/account/requests', '/account/quotes', '/account/orders', '/account/receipts'] as $url) {
        $this->actingAs($buyer)->get($url)
            ->assertOk()
            ->assertSee('name="robots" content="noindex, nofollow"', false);
    }
});

it('sends platform staff to the admin panel instead of the buyer dashboard', function () {
    $staff = dashBuyer();
    $staff->assignRole('admin');

    $this->actingAs($staff)->get('/account')->assertRedirect('/admin');
});

it('sends a company member to the exporter panel instead of the buyer dashboard', function () {
    $supplier = dashBuyer();
    $company = Company::factory()->publiclyVisible()->create();
    $company->users()->attach($supplier, ['role' => CompanyUserRole::Owner->value, 'is_primary' => true]);

    $this->actingAs($supplier)->get('/account')->assertRedirect('/dashboard');
});

/* ---------------------------------------------------------------- isolation */

it('never shows another buyer their records', function () {
    $mine = dashBuyer();
    $theirs = dashBuyer();

    $myRfq = dashRfq($mine, ['reference_code' => 'RFQ-MINE-0001']);
    $theirRfq = dashRfq($theirs, ['reference_code' => 'RFQ-THEIRS-001']);

    $myQuote = dashQuote($myRfq, null, 100.00, ['reference_code' => 'QTE-MINE-0001']);
    $theirQuote = dashQuote($theirRfq, null, 100.00, ['reference_code' => 'QTE-THEIRS-001']);

    $myOrder = dashAward($myQuote);
    $theirOrder = dashAward($theirQuote);

    $this->actingAs($mine)->get('/account/requests')
        ->assertSee('RFQ-MINE-0001')->assertDontSee('RFQ-THEIRS-001');

    $this->actingAs($mine)->get('/account/quotes')
        ->assertSee('QTE-MINE-0001')->assertDontSee('QTE-THEIRS-001');

    $this->actingAs($mine)->get('/account/orders')
        ->assertSee($myOrder->reference_code)->assertDontSee($theirOrder->reference_code);

    $this->actingAs($mine)->get('/account/receipts')
        ->assertSee($myOrder->receipt->receipt_number)
        ->assertDontSee($theirOrder->receipt->receipt_number);
});

it('refuses cross-buyer access by direct id on every deep link', function () {
    $mine = dashBuyer();
    $theirs = dashBuyer();

    $theirRfq = dashRfq($theirs);
    $theirQuote = dashQuote($theirRfq);
    $theirOrder = dashAward($theirQuote);

    $this->actingAs($mine);

    $this->get(route('buyer.rfq.responses', ['rfq' => $theirRfq->getKey()]))->assertForbidden();
    $this->get(route('buyer.rfq.quote', ['rfq' => $theirRfq->getKey(), 'quote' => $theirQuote->getKey()]))->assertForbidden();
    $this->get(route('buyer.rfq.order', ['rfq' => $theirRfq->getKey()]))->assertForbidden();
    $this->get(route('buyer.rfq.order.receipt', ['rfq' => $theirRfq->getKey()]))->assertForbidden();

    expect($theirOrder->receipt)->not->toBeNull();
});

it('keeps receipt verification tokens out of the account pages', function () {
    $buyer = dashBuyer();
    $order = dashAward(dashQuote(dashRfq($buyer)));
    $token = $order->receipt->verification_token;

    expect($token)->toBeString()->not->toBeEmpty();

    foreach (['/account', '/account/orders', '/account/receipts'] as $url) {
        $this->actingAs($buyer)->get($url)->assertOk()->assertDontSee($token);
    }
});

/* -------------------------------------------------------------------- stats */

it('drives every headline figure from a real query', function () {
    $buyer = dashBuyer();

    // Baseline: one RFQ, nothing else. Active requests = 1, everything else 0.
    $rfq = dashRfq($buyer);

    $read = fn () => app(BuyerDashboard::class)->stats($buyer->refresh());
    $value = fn (array $stats, string $key) => collect($stats)->firstWhere('key', $key)['value'];

    expect($value($read(), 'active_rfqs'))->toBe(1)
        ->and($value($read(), 'quotes_awaiting'))->toBe(0)
        ->and($value($read(), 'active_orders'))->toBe(0)
        ->and($value($read(), 'suppliers'))->toBe(0);

    // A quote arrives: quotes-to-review and suppliers-engaged both move.
    $quote = dashQuote($rfq);

    expect($value($read(), 'quotes_awaiting'))->toBe(1)
        ->and($value($read(), 'suppliers'))->toBe(1)
        ->and($value($read(), 'active_orders'))->toBe(0);

    // Awarding it moves the quote out of "to review" and creates an order.
    $order = dashAward($quote);

    expect($value($read(), 'quotes_awaiting'))->toBe(0)
        ->and($value($read(), 'active_orders'))->toBe(1)
        ->and($value($read(), 'orders_in_progress'))->toBe(0);

    // Confirming it moves "in progress".
    app(OrderService::class)->transition($order, OrderStatus::Confirmed);

    expect($value($read(), 'orders_in_progress'))->toBe(1);

    // Completing it takes it out of "active".
    $order->refresh();
    app(OrderService::class)->transition($order, OrderStatus::InProduction);
    app(OrderService::class)->transition($order->refresh(), OrderStatus::Shipped);
    app(OrderService::class)->transition($order->refresh(), OrderStatus::Delivered);
    app(OrderService::class)->transition($order->refresh(), OrderStatus::Completed);

    expect($value($read(), 'active_orders'))->toBe(0);
});

it('groups awarded value by currency and never sums across them', function () {
    $buyer = dashBuyer();

    dashAward(dashQuote(dashRfq($buyer), null, 100.00, ['currency' => 'USD']));
    dashAward(dashQuote(dashRfq($buyer), null, 50.00, ['currency' => 'EUR']));

    $rows = app(BuyerDashboard::class)->awardedValueThisYear($buyer);

    expect(collect($rows)->pluck('currency')->sort()->values()->all())->toBe(['EUR', 'USD'])
        ->and((float) collect($rows)->firstWhere('currency', 'USD')['total'])->toBe(10000.0)
        ->and((float) collect($rows)->firstWhere('currency', 'EUR')['total'])->toBe(5000.0);
});

it('counts orders by status from real rows', function () {
    $buyer = dashBuyer();
    $dashboard = app(BuyerDashboard::class);

    expect($dashboard->ordersByStatus($buyer))->toBeNull();

    dashAward(dashQuote(dashRfq($buyer)));
    dashAward(dashQuote(dashRfq($buyer)));

    $byStatus = $dashboard->ordersByStatus($buyer);

    expect($byStatus['total'])->toBe(2)
        ->and($byStatus['slices'][0]['status'])->toBe(OrderStatus::Awarded)
        ->and($byStatus['slices'][0]['count'])->toBe(2);
});

it('omits the value trend until there are two months of real data', function () {
    $buyer = dashBuyer();
    $dashboard = app(BuyerDashboard::class);

    expect($dashboard->valueTrend($buyer))->toBeNull();

    dashAward(dashQuote(dashRfq($buyer)));
    expect($dashboard->valueTrend($buyer))->toBeNull();

    // Backdate one order into a previous month — now the trend has two points.
    $older = dashAward(dashQuote(dashRfq($buyer)));
    $older->forceFill(['awarded_at' => now()->subMonthsNoOverflow(2)])->save();

    $trend = $dashboard->valueTrend($buyer);

    expect($trend)->not->toBeNull()
        ->and($trend['currency'])->toBe('USD')
        ->and($trend['points'])->toHaveCount(12)
        ->and($trend['total'])->toBe(37000.0);
});

/* ------------------------------------------------------------- empty states */

it('renders a useful first-run screen for a buyer with no activity', function () {
    $buyer = dashBuyer();

    $this->actingAs($buyer)->get('/account')
        ->assertOk()
        ->assertSee('Post an RFQ')
        ->assertSee(route('rfq.create'))
        ->assertSee(url('/marketplace'))
        // No stat tiles at all — a wall of zeroes is not a dashboard.
        ->assertDontSee('Suppliers engaged');

    $this->actingAs($buyer)->get('/account/requests')->assertOk()->assertSee('You have not posted a request yet');
    $this->actingAs($buyer)->get('/account/quotes')->assertOk()->assertSee('No quotes received yet');
    $this->actingAs($buyer)->get('/account/orders')->assertOk()->assertSee('No orders yet');
    $this->actingAs($buyer)->get('/account/receipts')->assertOk()->assertSee('No receipts yet');
});

/* -------------------------------------------------------------- pagination */

it('paginates the request list', function () {
    $buyer = dashBuyer();

    foreach (range(1, 12) as $i) {
        dashRfq($buyer, ['reference_code' => sprintf('RFQ-PAGE-%03d', $i)]);
    }

    $this->actingAs($buyer)->get('/account/requests')
        ->assertOk()
        ->assertSee('RFQ-PAGE-012')          // newest first
        ->assertDontSee('RFQ-PAGE-002')      // pushed onto page 2
        ->assertSee('account/requests?page=2', false);

    $this->actingAs($buyer)->get('/account/requests?page=2')
        ->assertOk()
        ->assertSee('RFQ-PAGE-002')
        ->assertDontSee('RFQ-PAGE-012');
});

/* ------------------------------------------------------------ query budget */

it('keeps the dashboard query count bounded as history grows', function () {
    $buyer = dashBuyer();
    $company = Company::factory()->publiclyVisible()->create();

    $count = function () use ($buyer) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($buyer)->get('/account')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    foreach (range(1, 3) as $i) {
        dashAward(dashQuote(dashRfq($buyer), $company));
    }

    // Warm the permission cache first, so the measurement is about the page's
    // own queries and not a one-off framework lookup.
    $count();
    $small = $count();

    foreach (range(1, 9) as $i) {
        dashAward(dashQuote(dashRfq($buyer), $company));
    }
    $large = $count();

    // Four times the data must not cost more queries: every list is eager
    // loaded and every aggregate is a single grouped query.
    expect($large)->toBe($small);
});

it('keeps the order list query count bounded as history grows', function () {
    $buyer = dashBuyer();
    $company = Company::factory()->publiclyVisible()->create();

    $count = function () use ($buyer) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($buyer)->get('/account/orders')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    dashAward(dashQuote(dashRfq($buyer), $company));
    $count();
    $small = $count();

    foreach (range(1, 8) as $i) {
        dashAward(dashQuote(dashRfq($buyer), $company));
    }

    expect($count())->toBe($small);
});
