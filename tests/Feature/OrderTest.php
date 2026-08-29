<?php

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Filament\Exporter\Resources\Orders\OrderResource;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use App\Models\User;
use App\Services\BuyerRfqAccess;
use App\Services\OrderService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/* ------------------------------------------------------------------ helpers */
/* Named distinctly from QuoteTest's helpers — Pest loads every test file into
   the same process, so a duplicate global function name is a fatal error. */

/** An approved RFQ routed to a fresh verified company. */
function orderContext(array $rfqAttributes = []): array
{
    $company = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create($rfqAttributes);
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

/** A submitted quote with one line: 100 m3 @ $185.00 = $18,500.00. */
function orderQuote(Rfq $rfq, Company $company, float $unitPrice = 185.00, array $overrides = []): Quote
{
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
        'description' => 'Sawn timber',
        'quantity' => 100,
        'unit_price' => $unitPrice,
        'line_total' => Quote::lineTotal(100, $unitPrice),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    return $quote;
}

/** Award through the real path and return the resulting order. */
function awardOrder(Quote $quote, ?User $actor = null): Order
{
    app(QuoteService::class)->accept($quote, $actor);

    return Order::where('quote_id', $quote->getKey())->firstOrFail();
}

function orderAccess(): BuyerRfqAccess
{
    return app(BuyerRfqAccess::class);
}

/* ------------------------------------------------------------- award wiring */

it('creates an order when a buyer accepts a quote', function () {
    [$rfq, $company] = orderContext();
    $quote = orderQuote($rfq, $company);

    $order = awardOrder($quote);

    expect($order->status)->toBe(OrderStatus::Awarded)
        ->and($order->reference_code)->toStartWith('ORD-')
        ->and((float) $order->total_amount)->toBe(18500.00)
        ->and($order->awarded_at)->not->toBeNull()
        ->and($order->rfq_id)->toBe($rfq->getKey())
        ->and($order->company_id)->toBe($company->getKey());
});

it('issues a receipt with an unguessable token alongside the order', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));

    $receipt = $order->receipt;

    expect($receipt)->not->toBeNull()
        ->and($receipt->receipt_number)->toStartWith('RCT-')
        ->and(strlen($receipt->verification_token))->toBe(40)
        // The token must not be derivable from anything printed on the document.
        ->and($receipt->verification_token)->not->toContain($receipt->receipt_number)
        ->and($receipt->verification_token)->not->toContain($order->reference_code)
        ->and((float) $receipt->amount)->toBe((float) $order->total_amount);
});

it('refuses to create an order from a quote that is not accepted', function () {
    [$rfq, $company] = orderContext();
    $quote = orderQuote($rfq, $company);

    expect(fn () => app(OrderService::class)->createFromQuote($quote))
        ->toThrow(RuntimeException::class, 'only be created from an accepted quote');

    expect(Order::count())->toBe(0);
});

it('is idempotent — a second createFromQuote returns the same order', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));

    $again = app(OrderService::class)->createFromQuote($order->quote->refresh());

    expect($again->getKey())->toBe($order->getKey())
        ->and(Order::count())->toBe(1);
});

/* --------------------------------------------------------- inventory (1.5.6) */

it('reserves inventory for a line item whose species matches a tracked product', function () {
    [$rfq, $company] = orderContext();
    $species = Species::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->getKey(), 'species_id' => $species->getKey()]);
    $inventory = Inventory::factory()->create(['product_id' => $product->getKey(), 'quantity_available' => 500]);

    $quote = orderQuote($rfq, $company);
    $quote->items()->update(['species_id' => $species->getKey(), 'quantity' => 100]);

    awardOrder($quote);

    expect((float) $inventory->fresh()->quantity_available)->toBe(400.0);
});

it('does not fail order creation when a line item has no matching Inventory row', function () {
    [$rfq, $company] = orderContext();
    $species = Species::factory()->create();
    Product::factory()->create(['company_id' => $company->getKey(), 'species_id' => $species->getKey()]);
    // No Inventory row created for this product — tracking is opt-in.

    $quote = orderQuote($rfq, $company);
    $quote->items()->update(['species_id' => $species->getKey()]);

    $order = awardOrder($quote);

    expect($order->status)->toBe(OrderStatus::Awarded);
});

it('does not block order creation when a line item exceeds available inventory', function () {
    [$rfq, $company] = orderContext();
    $species = Species::factory()->create();
    $product = Product::factory()->create(['company_id' => $company->getKey(), 'species_id' => $species->getKey()]);
    $inventory = Inventory::factory()->create(['product_id' => $product->getKey(), 'quantity_available' => 10]);

    $quote = orderQuote($rfq, $company);
    $quote->items()->update(['species_id' => $species->getKey(), 'quantity' => 100]);

    $order = awardOrder($quote);

    expect($order->status)->toBe(OrderStatus::Awarded)
        ->and((float) $inventory->fresh()->quantity_available)->toBe(10.0);
});

it('lets the database, not just the guard, enforce one order per quote', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));

    // Bypass the service entirely: the unique index must still refuse.
    $duplicate = $order->replicate(['reference_code']);
    $duplicate->reference_code = 'ORD-DUPLICATE-1';

    // Wrapped in a nested transaction so the failed INSERT rolls back to a
    // savepoint; without it the aborted transaction poisons the rest of the test.
    expect(fn () => DB::transaction(fn () => $duplicate->save()))->toThrow(QueryException::class);

    expect(Order::where('quote_id', $order->quote_id)->count())->toBe(1);
});

it('rolls the whole award back when order creation fails', function () {
    [$rfq, $company] = orderContext();
    $quote = orderQuote($rfq, $company);
    $other = orderQuote($rfq, Company::factory()->publiclyVisible()->create(), 190.00);

    // Force the order insert to fail after the quote transitions have been
    // written inside the transaction.
    Order::creating(fn () => throw new RuntimeException('boom'));

    try {
        expect(fn () => app(QuoteService::class)->accept($quote))->toThrow(RuntimeException::class, 'boom');
    } finally {
        Order::flushEventListeners();
    }

    // Nothing survived: no order, and the quotes and RFQ are untouched.
    expect(Order::count())->toBe(0)
        ->and($quote->refresh()->status)->toBe(QuoteStatus::Submitted)
        ->and($other->refresh()->status)->toBe(QuoteStatus::Submitted)
        ->and($rfq->refresh()->status->value)->toBe('approved');
});

it('writes an activity log entry when the order is created', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));

    expect(Activity::where('log_name', 'order')
        ->where('subject_id', $order->getKey())
        ->where('event', 'created')
        ->exists())->toBeTrue();
});

/* ---------------------------------------------------------------- snapshots */

it('snapshots the line items so later quote edits cannot rewrite the order', function () {
    [$rfq, $company] = orderContext();
    $quote = orderQuote($rfq, $company);
    $order = awardOrder($quote);

    $before = $order->items->first()->only(['description', 'quantity', 'unit_price', 'line_total', 'dimensions', 'grade']);

    // The supplier rewrites their quote after the fact.
    $quote->items->each->update([
        'description' => 'Something else entirely',
        'quantity' => 5,
        'unit_price' => 1.00,
        'line_total' => '5.00',
        'dimensions' => 'changed',
        'grade' => 'Grade Z',
    ]);
    $quote->forceFill(['subtotal_amount' => 5, 'total_amount' => 5])->save();

    $order->refresh()->load('items');

    expect($order->items->first()->only(array_keys($before)))->toEqual($before)
        ->and((float) $order->total_amount)->toBe(18500.00)
        ->and((float) $order->subtotal_amount)->toBe(18500.00);
});

it('snapshots buyer and supplier details so later profile edits cannot rewrite the order', function () {
    [$rfq, $company] = orderContext([
        'buyer_name' => 'Marta Devos',
        'buyer_company' => 'Devos Hardwoods NV',
        'buyer_email' => 'marta@devos.test',
        'buyer_country_code' => 'BE',
    ]);
    $order = awardOrder(orderQuote($rfq, $company));

    $supplierAtAward = $order->supplier_name;

    $rfq->update([
        'buyer_name' => 'Somebody Else',
        'buyer_company' => 'Other Co',
        'buyer_email' => 'other@example.test',
        'buyer_country_code' => 'FR',
    ]);
    $company->update(['trade_name' => 'Renamed Timber Ltd']);

    $order->refresh();

    expect($order->buyer_name)->toBe('Marta Devos')
        ->and($order->buyer_company)->toBe('Devos Hardwoods NV')
        ->and($order->buyer_email)->toBe('marta@devos.test')
        ->and($order->buyer_country_code)->toBe('BE')
        ->and($order->supplier_name)->toBe($supplierAtAward)
        ->and($order->supplier_name)->not->toBe('Renamed Timber Ltd');
});

/* ----------------------------------------------------------- state machine */

it('walks the legal lifecycle', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));
    $orders = app(OrderService::class);

    $order = $orders->confirm($order);
    expect($order->status)->toBe(OrderStatus::Confirmed)->and($order->confirmed_at)->not->toBeNull();

    $order = $orders->startProduction($order);
    expect($order->status)->toBe(OrderStatus::InProduction)->and($order->production_started_at)->not->toBeNull();

    $order = $orders->ship($order);
    expect($order->status)->toBe(OrderStatus::Shipped)->and($order->shipped_at)->not->toBeNull();

    $order = $orders->deliver($order);
    expect($order->status)->toBe(OrderStatus::Delivered)->and($order->delivered_at)->not->toBeNull();

    $order = $orders->complete($order);
    expect($order->status)->toBe(OrderStatus::Completed)->and($order->completed_at)->not->toBeNull();
});

it('cannot ship an order before it is confirmed', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));

    expect(fn () => app(OrderService::class)->ship($order))
        ->toThrow(RuntimeException::class, 'Illegal order transition awarded -> shipped');

    expect($order->refresh()->status)->toBe(OrderStatus::Awarded)
        ->and($order->shipped_at)->toBeNull();
});

it('cannot cancel a completed order', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));
    $orders = app(OrderService::class);

    $order = $orders->complete($orders->deliver($orders->ship($orders->confirm($order))));

    expect(fn () => $orders->cancel($order, 'Changed my mind'))
        ->toThrow(RuntimeException::class, 'Illegal order transition completed -> cancelled');

    expect($order->refresh()->status)->toBe(OrderStatus::Completed);
});

it('records a cancellation reason and logs the transition', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));

    $order = app(OrderService::class)->cancel($order, 'Buyer withdrew the project');

    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->cancellation_reason)->toBe('Buyer withdrew the project')
        ->and($order->cancelled_at)->not->toBeNull();

    expect(Activity::where('log_name', 'order')
        ->where('subject_id', $order->getKey())
        ->where('event', 'status_changed')
        ->get()
        ->contains(fn (Activity $a) => ($a->properties['to'] ?? null) === 'cancelled'))->toBeTrue();
});

it('requires a reason to cancel', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));

    expect(fn () => app(OrderService::class)->cancel($order, '   '))
        ->toThrow(RuntimeException::class, 'cancellation reason is required');
});

/* ------------------------------------------------------------- settlement */

it('defaults to no payment recorded and never sets one automatically', function () {
    [$rfq, $company] = orderContext();
    $orders = app(OrderService::class);
    $order = awardOrder(orderQuote($rfq, $company));

    expect($order->payment_status)->toBe(OrderPaymentStatus::Unpaid)
        ->and((float) $order->amount_paid)->toBe(0.0);

    // Walking the whole lifecycle must not invent a settlement.
    $order = $orders->complete($orders->deliver($orders->ship($orders->confirm($order))));

    expect($order->payment_status)->toBe(OrderPaymentStatus::Unpaid)
        ->and((float) $order->amount_paid)->toBe(0.0);
});

it('records an off-platform settlement only when staff enter it', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));
    $orders = app(OrderService::class);

    $order = $orders->recordPayment($order, 5550.00, 'Bank transfer');
    expect($order->payment_status)->toBe(OrderPaymentStatus::PartiallyPaid)
        ->and((float) $order->balanceDue())->toBe(12950.00);

    $order = $orders->recordPayment($order, 18500.00, 'Bank transfer');
    expect($order->payment_status)->toBe(OrderPaymentStatus::Paid)
        ->and((float) $order->balanceDue())->toBe(0.0);

    expect(fn () => $orders->recordPayment($order, 99999.00))
        ->toThrow(RuntimeException::class, 'cannot exceed the order total');
});

/* --------------------------------------------------------- buyer access */

it('shows a guest buyer their order through a signed link', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));

    $this->get(orderAccess()->orderUrl($rfq))
        ->assertOk()
        ->assertSee($order->reference_code)
        ->assertSee($order->supplier_name)
        ->assertSee('18,500.00');
});

it('rejects an unsigned or tampered request for the order screen', function () {
    [$rfq, $company] = orderContext();
    awardOrder(orderQuote($rfq, $company));

    $url = orderAccess()->orderUrl($rfq);

    $this->get(route('buyer.rfq.order', ['rfq' => $rfq->getKey()]))->assertForbidden();
    $this->get($url.'x')->assertForbidden();
    $this->get(str_replace('signature=', 'signature=deadbeef', $url))->assertForbidden();
});

it('does not let one buyer reach another buyer order', function () {
    [$mine, $companyA] = orderContext();
    [$theirs, $companyB] = orderContext();
    awardOrder(orderQuote($mine, $companyA));
    $secret = awardOrder(orderQuote($theirs, $companyB, 999.00));

    $mineUrl = orderAccess()->orderUrl($mine);

    // The signature covers the RFQ id, so swapping it kills the link...
    $swapped = str_replace('/rfq/'.$mine->getKey().'/', '/rfq/'.$theirs->getKey().'/', $mineUrl);
    $this->get($swapped)->assertForbidden();

    // ...and the legitimate link never leaks the other order.
    $this->get($mineUrl)->assertOk()->assertDontSee($secret->reference_code);
});

it('lets the signed-in RFQ owner reach their order without a signature', function () {
    $user = User::factory()->create();
    [$rfq, $company] = orderContext(['user_id' => $user->getKey()]);
    $order = awardOrder(orderQuote($rfq, $company));

    $this->actingAs($user)
        ->get(route('buyer.rfq.order', ['rfq' => $rfq->getKey()]))
        ->assertOk()
        ->assertSee($order->reference_code);

    // A different signed-in user is still refused.
    $this->actingAs(User::factory()->create())
        ->get(route('buyer.rfq.order', ['rfq' => $rfq->getKey()]))
        ->assertForbidden();
});

/* --------------------------------------------------------------- screens */

it('renders the award review screen without awarding anything', function () {
    [$rfq, $company] = orderContext();
    $quote = orderQuote($rfq, $company);

    $this->get(orderAccess()->awardUrl($rfq, $quote))
        ->assertOk()
        ->assertSee('Award this order')
        ->assertSee($quote->reference_code)
        ->assertSee('18,500.00');

    // A GET must never change state.
    expect($quote->refresh()->status)->not->toBe(QuoteStatus::Accepted)
        ->and(Order::count())->toBe(0);
});

it('awards through a POST and lands the buyer on the order', function () {
    [$rfq, $company] = orderContext();
    $quote = orderQuote($rfq, $company);

    $this->post(orderAccess()->acceptUrl($rfq, $quote), ['confirm' => '1'])
        ->assertRedirect();

    expect($quote->refresh()->status)->toBe(QuoteStatus::Accepted)
        ->and(Order::where('quote_id', $quote->getKey())->exists())->toBeTrue();
});

it('refuses to award without the confirmation checkbox', function () {
    [$rfq, $company] = orderContext();
    $quote = orderQuote($rfq, $company);

    $this->post(orderAccess()->acceptUrl($rfq, $quote), [])
        ->assertSessionHasErrors('confirm');

    expect(Order::count())->toBe(0);
});

it('renders a printable receipt with the real totals and no invented payment', function () {
    [$rfq, $company] = orderContext();
    $order = awardOrder(orderQuote($rfq, $company));

    $this->get(orderAccess()->receiptUrl($rfq))
        ->assertOk()
        ->assertSee($order->receipt->receipt_number)
        ->assertSee($order->reference_code)
        ->assertSee('18,500.00')
        ->assertSee('receipt-sheet', false)          // the print-scoped wrapper
        ->assertSee('No payment recorded')
        ->assertSee('not a proof of payment');
});

it('refuses an unsigned request for the receipt screen', function () {
    [$rfq, $company] = orderContext();
    awardOrder(orderQuote($rfq, $company));

    $this->get(route('buyer.rfq.order.receipt', ['rfq' => $rfq->getKey()]))->assertForbidden();
});

it('keeps every buyer order screen out of search indexes', function () {
    [$rfq, $company] = orderContext();
    $quote = orderQuote($rfq, $company);
    awardOrder($quote);

    foreach ([orderAccess()->orderUrl($rfq), orderAccess()->receiptUrl($rfq)] as $url) {
        $this->get($url)->assertOk()->assertSee('noindex', false);
    }
});

/* -------------------------------------------------------- supplier panel */

it('shows a supplier only their own orders in the exporter panel', function () {
    [$mine, $companyA] = orderContext();
    [$theirs, $companyB] = orderContext();
    $mineOrder = awardOrder(orderQuote($mine, $companyA));
    $theirOrder = awardOrder(orderQuote($theirs, $companyB, 210.00));

    $user = User::factory()->create();
    $user->companies()->attach($companyA, ['role' => 'owner', 'is_primary' => true]);

    $this->actingAs($user);

    $visible = OrderResource::getEloquentQuery()->pluck('id');

    expect($visible)->toContain($mineOrder->getKey())
        ->and($visible)->not->toContain($theirOrder->getKey());
});
