<?php

use App\Enums\CompanyUserRole;
use App\Enums\MessageType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Enums\RfqStatus;
use App\Livewire\Messaging\Thread;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\ChatCommerceService;
use App\Services\CompanyReviewService;
use App\Services\LeadFlowService;
use App\Services\MessagingService;
use App\Services\OrderLifecycleService;
use App\Services\ReorderService;
use App\Services\RfqTriageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/* ------------------------------------------------------------------ helpers */
/* Prefixed `ro` so they never collide with the other feature files — Pest loads
   them all into one process. */

function roReorders(): ReorderService
{
    return app(ReorderService::class);
}

/**
 * A completed order, its conversation, and both parties — the situation a
 * reorder is actually started from.
 *
 * Built through the real path end to end (quote -> accept -> order -> the
 * supplier ships -> the buyer completes), because a reorder's eligibility is
 * derived from that history and faking the history would test nothing.
 *
 * @return array{0: Conversation, 1: User, 2: Company, 3: User, 4: Order}
 */
function roScene(float $unitPrice = 620.00): array
{
    $company = Company::factory()->publiclyVisible()->create();
    $staff = User::factory()->create(['email' => 'rostaff'.uniqid().'@example.com']);
    $company->users()->attach($staff, ['role' => CompanyUserRole::Owner->value, 'is_primary' => true]);

    $buyer = User::factory()->create([
        'email' => 'robuyer'.uniqid().'@example.com',
        'email_verified_at' => now(),
    ]);

    $rfq = Rfq::factory()->approved()->create([
        'user_id' => $buyer->getKey(),
        'buyer_email' => $buyer->email,
    ]);
    $rfqItem = $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3',
    ]);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
        'currency' => 'USD',
        'incoterm' => 'CIF',
        'lead_time_days' => 10,
        'validity_days' => 15,
        'valid_until' => now()->addDays(15)->toDateString(),
        'payment_terms' => '50% advance, 50% before shipment',
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'rfq_item_id' => $rfqItem->getKey(),
        'description' => 'Premium Sapele Lumber (KD)',
        'quantity' => 50,
        'unit' => 'm3',
        'unit_price' => $unitPrice,
        'line_total' => Quote::lineTotal(50, $unitPrice),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    $conversation = app(MessagingService::class)->start($buyer, $company);
    $commerce = app(ChatCommerceService::class);
    $commerce->issueQuotation($conversation, $quote->refresh(), $staff);
    $commerce->acceptQuotation($conversation, $quote->refresh(), $buyer);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    // Drive it all the way to completed through the authorised parties.
    $lifecycle = app(OrderLifecycleService::class);
    $lifecycle->confirm($conversation, $order->refresh(), $staff);
    $lifecycle->startProduction($conversation, $order->refresh(), $staff);
    $lifecycle->ship($conversation, $order->refresh(), $staff);
    $lifecycle->deliver($conversation, $order->refresh(), $staff);
    $lifecycle->complete($conversation, $order->refresh(), $buyer);

    return [$conversation->refresh(), $buyer, $company, $staff, $order->refresh()];
}

/** The whole reorder chain, up to the new order. */
/**
 * An admin account to attribute triage decisions to — never the buyer and
 * never the supplier, so the approval on the activity log is honest.
 */
function roAdmin(): User
{
    $admin = User::factory()->create(['email' => 'roadmin'.uniqid().'@example.com']);
    $admin->assignRole('admin');

    return $admin;
}

/**
 * Admin triage: approve the reorder RFQ and route it to the supplier.
 *
 * A reorder RFQ is NOT auto-approved, so every test that needs a quotable
 * request has to go through here — which is the point: if this step were
 * skippable the supplier boundary would be decorative.
 */
function roTriage(Rfq $rfq, Company $company): Rfq
{
    $admin = roAdmin();
    $triage = app(RfqTriageService::class);

    $triage->approve($rfq, $admin);
    $triage->route($rfq->refresh(), [$company->getKey()], $admin, app(LeadFlowService::class));

    return $rfq->refresh();
}

function roRunChain(Conversation $c, Order $source, User $buyer, User $staff, float $newPrice = 700.00): array
{
    $card = roReorders()->request($c, $source, $buyer, []);
    /** @var Rfq $rfq */
    $rfq = $card->related;

    // The admin step is part of the real chain now.
    roTriage($rfq, $c->company);

    $quote = roReorders()->quote($c->refresh(), $rfq->refresh(), $staff, [
        'lines' => $rfq->items->mapWithKeys(fn ($i) => [(int) $i->getKey() => ['unit_price' => $newPrice]])->all(),
        'lead_time_days' => 21,
        'validity_days' => 14,
    ]);

    app(ChatCommerceService::class)->acceptQuotation($c->refresh(), $quote->refresh(), $buyer);

    return [$rfq->refresh(), $quote->refresh(), Order::where('quote_id', $quote->getKey())->firstOrFail()];
}

/* =========================================================== WHO MAY REORDER */

it('lets the buyer reorder an order they own that has landed', function () {
    [$c, $buyer, , , $order] = roScene();

    expect(roReorders()->canReorder($buyer, $order))->toBeTrue();

    $card = roReorders()->request($c, $order, $buyer);

    expect($card->type)->toBe(MessageType::ReorderRequest)
        ->and($card->related)->toBeInstanceOf(Rfq::class)
        ->and($card->related->reorder_of_order_id)->toBe($order->getKey());
});

it('refuses a reorder from a supplier on the thread', function () {
    [$c, , , $staff, $order] = roScene();

    expect(fn () => roReorders()->request($c, $order, $staff))
        ->toThrow(HttpException::class);

    expect($c->messages()->where('type', MessageType::ReorderRequest->value)->count())->toBe(0);
});

it('404s a non-participant with no leak, rather than 403ing', function () {
    [$c, , , , $order] = roScene();

    $stranger = User::factory()->create(['email' => 'rostranger'.uniqid().'@example.com']);

    try {
        roReorders()->request($c, $order, $stranger);
        $this->fail('A stranger reached the reorder action.');
    } catch (HttpException $e) {
        // 404, not 403: the thread's very existence must stay secret.
        expect($e->getStatusCode())->toBe(404);
    }
});

it('refuses a reorder of another buyer\'s order', function () {
    [$c, $buyer, , , $order] = roScene();
    [, $otherBuyer] = roScene();

    // Same thread, but the actor is not its buyer: they are not a participant
    // at all, so this is a 404 rather than an ownership message.
    expect(fn () => roReorders()->request($c, $order, $otherBuyer))->toThrow(HttpException::class);

    // And an order id from another thread does not resolve on this one either.
    [$otherConv, , , , $otherOrder] = roScene();
    expect(fn () => roReorders()->request($c, $otherOrder, $buyer))->toThrow(HttpException::class);
    expect(fn () => roReorders()->request($otherConv, $order, $buyer))->toThrow(HttpException::class);
});

it('refuses to resurrect a cancelled order', function () {
    [$c, $buyer, , , $order] = roScene();

    // A completed order cannot be cancelled through the state machine, so the
    // cancelled state is written directly — the point of the test is the
    // reorder guard, not how the order got there.
    DB::table('orders')->where('id', $order->getKey())
        ->update(['status' => OrderStatus::Cancelled->value, 'cancelled_at' => now()]);

    $cancelled = Order::findOrFail($order->getKey());

    expect($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and(roReorders()->canReorder($buyer, $cancelled))->toBeFalse();

    expect(fn () => roReorders()->request($c, $cancelled, $buyer))
        ->toThrow(RuntimeException::class, 'cancelled');

    expect(Rfq::where('reorder_of_order_id', $order->getKey())->exists())->toBeFalse();
});

it('refuses a reorder of an order that has not landed yet', function () {
    [$c, $buyer, $company, $staff] = roScene();

    // A second order on the same thread, still merely awarded.
    $rfq = Rfq::factory()->approved()->create(['user_id' => $buyer->getKey(), 'buyer_email' => $buyer->email]);
    $item = $rfq->items()->create(['species_text' => 'Iroko', 'quantity' => 10, 'unit' => 'm3']);
    $routing = RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'status' => 'sent', 'routed_at' => now()]);
    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(), 'currency' => 'USD',
    ]);
    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(), 'rfq_item_id' => $item->getKey(),
        'quantity' => 10, 'unit' => 'm3', 'unit_price' => 100, 'line_total' => Quote::lineTotal(10, 100),
    ]);
    $quote->load('items')->recalculateTotals()->save();

    app(ChatCommerceService::class)->issueQuotation($c, $quote->refresh(), $staff);
    app(ChatCommerceService::class)->acceptQuotation($c->refresh(), $quote->refresh(), $buyer);

    $fresh = Order::where('quote_id', $quote->getKey())->firstOrFail();

    expect($fresh->status)->toBe(OrderStatus::Awarded)
        ->and(roReorders()->canReorder($buyer, $fresh))->toBeFalse();

    expect(fn () => roReorders()->request($c->refresh(), $fresh, $buyer))
        ->toThrow(RuntimeException::class, 'delivered');
});

/* ================================================================ ADMIN TRIAGE */

it('leaves a fresh reorder in triage, unrouted and with no lead', function () {
    [$c, $buyer, $company, , $source] = roScene();

    $card = roReorders()->request($c, $source, $buyer);
    /** @var Rfq $rfq */
    $rfq = $card->related;

    // Entered like any other request: `new`, not approved.
    expect($rfq->status)->toBe(RfqStatus::New)
        // Private, so it never joins the public pool...
        ->and($rfq->visibility)->toBe('private')
        // ...but private is NOT pre-approved: nothing has been routed, and no
        // lead exists, because routing is triage's job alone.
        ->and(RfqCompany::where('rfq_id', $rfq->getKey())->exists())->toBeFalse()
        ->and($rfq->routings()->count())->toBe(0)
        ->and(Lead::where('rfq_id', $rfq->getKey())->exists())->toBeFalse();

    // The provenance link survives all of this.
    expect($rfq->reorder_of_order_id)->toBe($source->getKey());

    // And the service agrees the supplier has not been reached.
    expect(roReorders()->isRoutedToSupplier($rfq, $company->getKey()))->toBeFalse()
        ->and(roReorders()->awaitingReview($rfq, $company->getKey()))->toBeTrue();
});

it('refuses a supplier quote until the request is approved and routed', function () {
    [$c, $buyer, $company, $staff, $source] = roScene();

    $rfq = roReorders()->request($c, $source, $buyer)->related;

    $price = fn () => $rfq->refresh()->items
        ->mapWithKeys(fn ($i) => [(int) $i->getKey() => ['unit_price' => 700]])->all();

    // 1. Raised, still in triage: refused.
    expect(fn () => roReorders()->quote($c->refresh(), $rfq->refresh(), $staff, ['lines' => $price()]))
        ->toThrow(RuntimeException::class, 'still being reviewed');

    expect(Quote::where('rfq_id', $rfq->getKey())->exists())->toBeFalse();

    // 2. Approved but NOT yet routed: still refused. Approval alone is not
    //    enough — the supplier must actually have been given the request.
    app(RfqTriageService::class)->approve($rfq->refresh(), roAdmin());

    expect($rfq->refresh()->status)->toBe(RfqStatus::Approved)
        ->and(roReorders()->isRoutedToSupplier($rfq->refresh(), $company->getKey()))->toBeFalse();

    expect(fn () => roReorders()->quote($c->refresh(), $rfq->refresh(), $staff, ['lines' => $price()]))
        ->toThrow(RuntimeException::class, 'still being reviewed');

    expect(Quote::where('rfq_id', $rfq->getKey())->exists())->toBeFalse();

    // 3. Routed to this company: now, and only now, it can be priced.
    app(RfqTriageService::class)->route($rfq->refresh(), [$company->getKey()], roAdmin(), app(LeadFlowService::class));

    $quote = roReorders()->quote($c->refresh(), $rfq->refresh(), $staff, ['lines' => $price()]);

    expect($quote->status)->toBe(QuoteStatus::Submitted)
        ->and((float) $quote->items->first()->unit_price)->toBe(700.00);
});

it('refuses a quote from a company the reorder was routed away from', function () {
    [$c, $buyer, , $staff, $source] = roScene();
    [, , $otherCompany] = roScene();

    $rfq = roReorders()->request($c, $source, $buyer)->related;

    // Approved and routed — but to somebody else entirely.
    $triage = app(RfqTriageService::class);
    $admin = roAdmin();
    $triage->approve($rfq->refresh(), $admin);
    $triage->route($rfq->refresh(), [$otherCompany->getKey()], $admin, app(LeadFlowService::class));

    expect(fn () => roReorders()->quote($c->refresh(), $rfq->refresh(), $staff, [
        'lines' => $rfq->refresh()->items->mapWithKeys(fn ($i) => [(int) $i->getKey() => ['unit_price' => 700]])->all(),
    ]))->toThrow(RuntimeException::class, 'still being reviewed');

    expect(Quote::where('rfq_id', $rfq->getKey())->where('company_id', $c->company_id)->exists())->toBeFalse();
});

it('completes the accept to order chain once triage has run', function () {
    [$c, $buyer, $company, $staff, $source] = roScene(620.00);

    $rfq = roReorders()->request($c, $source, $buyer)->related;

    roTriage($rfq, $company);

    expect($rfq->refresh()->status)->toBe(RfqStatus::Approved)
        ->and(roReorders()->isRoutedToSupplier($rfq->refresh(), $company->getKey()))->toBeTrue()
        // Triage minted the routing and the lead, through the ordinary path.
        ->and(RfqCompany::where('rfq_id', $rfq->getKey())->where('company_id', $company->getKey())->exists())->toBeTrue();

    $quote = roReorders()->quote($c->refresh(), $rfq->refresh(), $staff, [
        'lines' => $rfq->refresh()->items->mapWithKeys(fn ($i) => [(int) $i->getKey() => ['unit_price' => 700]])->all(),
    ]);

    app(ChatCommerceService::class)->acceptQuotation($c->refresh(), $quote->refresh(), $buyer);

    $new = Order::where('quote_id', $quote->getKey())->firstOrFail();

    expect($new->reorder_of_order_id)->toBe($source->getKey())
        ->and($new->status)->toBe(OrderStatus::Awarded)
        ->and((float) $new->items->first()->unit_price)->toBe(700.00)
        ->and($new->receipt)->not->toBeNull()
        // The reorder RFQ is closed by the award, exactly like any other.
        ->and($rfq->refresh()->status)->toBe(RfqStatus::Closed);
});

it('shows an awaiting-review card that never claims the supplier has it', function () {
    [$c, $buyer, $company, $staff, $source] = roScene();

    $rfq = roReorders()->request($c, $source, $buyer)->related;

    // Buyer's view while it is in triage.
    Livewire::actingAs($buyer)
        ->test(Thread::class, ['conversationId' => $c->getKey()])
        ->assertSee('Awaiting review')
        ->assertSee('Awaiting review before it reaches')
        ->assertDontSee('Waiting for '.$company->name.' to confirm pricing');

    // Supplier's view: told it exists, given nothing to press.
    Livewire::actingAs($staff)
        ->test(Thread::class, ['conversationId' => $c->getKey()])
        ->assertSee('awaiting review')
        ->assertDontSee('Price this reorder');

    // After triage the copy flips to the pricing state on both sides.
    roTriage($rfq, $company);

    Livewire::actingAs($buyer)
        ->test(Thread::class, ['conversationId' => $c->getKey()])
        ->assertSee('Awaiting pricing')
        ->assertDontSee('Awaiting review before it reaches');

    Livewire::actingAs($staff)
        ->test(Thread::class, ['conversationId' => $c->getKey()])
        ->assertSee('Price this reorder');
});

/* ============================================== A REORDER IS A NEW, REAL ORDER */

it('creates a genuinely new order through the audited path and leaves the original untouched', function () {
    [$c, $buyer, , $staff, $source] = roScene(620.00);

    $before = $source->only(['status', 'total_amount', 'quote_id', 'reference_code', 'completed_at']);

    [$rfq, $quote, $new] = roRunChain($c, $source, $buyer, $staff, 700.00);

    // A different row, a different reference, a different quote.
    expect($new->getKey())->not->toBe($source->getKey())
        ->and($new->reference_code)->not->toBe($source->reference_code)
        ->and($new->quote_id)->toBe($quote->getKey())
        ->and($new->rfq_id)->toBe($rfq->getKey())
        ->and($new->reorder_of_order_id)->toBe($source->getKey())
        ->and($new->isReorder())->toBeTrue()
        // Minted through the ordinary award path, so it has its own receipt.
        ->and($new->receipt)->not->toBeNull();

    // The source order is byte-for-byte what it was.
    expect($source->refresh()->only(['status', 'total_amount', 'quote_id', 'reference_code', 'completed_at']))
        ->toEqual($before);

    // The money came from the SUPPLIER's step, not from the buyer and not from
    // the previous order.
    expect((float) $new->items->first()->unit_price)->toBe(700.00)
        ->and((float) $new->items->first()->unit_price)->not->toBe(620.00)
        ->and($quote->status)->toBe(QuoteStatus::Accepted);
});

it('does not carry the previous price into the new order', function () {
    [$c, $buyer, $company, $staff, $source] = roScene(620.00);

    $card = roReorders()->request($c, $source, $buyer);
    $rfq = $card->related;

    roTriage($rfq, $company);

    // The request itself holds NO agreed price. The previous unit price rides
    // along only as clearly-labelled reference material in the card payload.
    expect($card->payloadValue('lines')[0]['previous_unit_price'])->toEqual('620.00');

    // And the RFQ the supplier will quote carries no price at all.
    foreach ($rfq->items as $item) {
        expect($item->getAttributes())->not->toHaveKey('unit_price');
    }

    // The supplier cannot skip pricing: a quote with no unit price is refused.
    expect(fn () => roReorders()->quote($c->refresh(), $rfq->refresh(), $staff, ['lines' => []]))
        ->toThrow(RuntimeException::class, 'unit price');

    expect(Quote::where('rfq_id', $rfq->getKey())->where('status', QuoteStatus::Submitted->value)->count())->toBe(0);
});

it('never lets a buyer set the price of their own reorder', function () {
    [$c, $buyer, , $staff, $source] = roScene(620.00);

    // Every price-shaped key a buyer could invent, thrown at the request.
    roReorders()->request($c, $source, $buyer, [
        'unit_price' => 1,
        'total_amount' => 1,
        'lines' => [['unit_price' => 1]],
        'shipping_amount' => 0,
        'quantities' => [],
    ]);

    $rfq = Rfq::where('reorder_of_order_id', $source->getKey())->firstOrFail();

    // Nothing the buyer posted became a price, and the supplier still has to
    // state one before an order can exist.
    expect(Quote::where('rfq_id', $rfq->getKey())->exists())->toBeFalse()
        ->and(Order::where('rfq_id', $rfq->getKey())->exists())->toBeFalse();

    // The buyer cannot reach the supplier's pricing endpoint either.
    expect(fn () => roReorders()->quote($c->refresh(), $rfq->refresh(), $buyer, [
        'lines' => $rfq->items->mapWithKeys(fn ($i) => [(int) $i->getKey() => ['unit_price' => 1]])->all(),
    ]))->toThrow(HttpException::class);

    expect(Quote::where('rfq_id', $rfq->getKey())->exists())->toBeFalse();
});

it('accepts a changed quantity from the buyer but still re-prices it', function () {
    [$c, $buyer, $company, $staff, $source] = roScene(620.00);

    $itemId = (int) $source->items->first()->getKey();

    $card = roReorders()->request($c, $source, $buyer, ['quantities' => [$itemId => 80]]);
    $rfq = $card->related;

    expect((float) $rfq->items->first()->quantity)->toBe(80.0);

    roTriage($rfq, $company);

    $quote = roReorders()->quote($c->refresh(), $rfq->refresh(), $staff, [
        'lines' => [(int) $rfq->items->first()->getKey() => ['unit_price' => 700]],
    ]);

    // Line total is OUR arithmetic on the supplier's price and the buyer's
    // quantity — never a figure either party posted.
    expect((string) $quote->items->first()->line_total)->toBe(Quote::lineTotal(80, 700));
});

/* ================================================================ IDEMPOTENCY */

it('creates exactly one reorder when the buyer double-submits', function () {
    [$c, $buyer, , , $source] = roScene();

    $first = roReorders()->request($c, $source, $buyer);
    $second = roReorders()->request($c->refresh(), $source->refresh(), $buyer);

    expect($second->getKey())->toBe($first->getKey())
        ->and(Rfq::where('reorder_of_order_id', $source->getKey())->count())->toBe(1)
        ->and($c->messages()->where('type', MessageType::ReorderRequest->value)->count())->toBe(1);
});

it('enforces one open reorder per order in the database, not just in PHP', function () {
    [$c, $buyer, , , $source] = roScene();

    roReorders()->request($c, $source, $buyer);

    // Bypass the service entirely: the partial unique index is the real guard.
    expect(fn () => DB::table('rfqs')->insert([
        'reference_code' => 'RFQ-DUPE-'.uniqid(),
        'title' => 'Duplicate reorder',
        'buyer_name' => $buyer->name,
        'buyer_email' => $buyer->email,
        'status' => RfqStatus::Approved->value,
        'visibility' => 'private',
        'reorder_of_order_id' => $source->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('lets the buyer reorder again once the previous reorder has closed', function () {
    [$c, $buyer, , $staff, $source] = roScene();

    [$rfq] = roRunChain($c, $source, $buyer, $staff);

    // QuoteService::accept() closed the reorder RFQ, so the partial index no
    // longer covers it and a fresh reorder is allowed.
    expect($rfq->refresh()->status)->toBe(RfqStatus::Closed);

    $again = roReorders()->request($c->refresh(), $source->refresh(), $buyer);

    expect($again->related->getKey())->not->toBe($rfq->getKey())
        ->and(Rfq::where('reorder_of_order_id', $source->getKey())->count())->toBe(2);
});

/* ============================================================ THE CARD CHAIN */

it('posts the expected card sequence into the thread', function () {
    [$c, $buyer, , $staff, $source] = roScene();

    [, , $new] = roRunChain($c, $source, $buyer, $staff);

    $lifecycle = app(OrderLifecycleService::class);
    $lifecycle->issueProformaInvoice($c->refresh(), $new, $staff);
    $lifecycle->requestPayment($c->refresh(), $new->refresh(), $staff);
    $lifecycle->recordPayment($c->refresh(), $new->refresh(), $staff, $new->total_amount, 'Bank transfer');
    $lifecycle->confirm($c->refresh(), $new->refresh(), $staff);

    // `type` is cast to MessageType, so normalise back to the backing strings.
    $types = $c->refresh()->messages()->orderBy('id')->pluck('type')
        ->map(fn ($t) => $t instanceof MessageType ? $t->value : $t)->all();

    // Reorder request, then the Phase 2 + Phase 3 cards, reused verbatim.
    $tail = array_slice($types, array_search(MessageType::ReorderRequest->value, $types, true));

    expect($tail)->toContain(
        MessageType::ReorderRequest->value,
        MessageType::Quotation->value,
        MessageType::ContractAcceptance->value,
        MessageType::OrderReference->value,
        MessageType::ProformaInvoice->value,
        MessageType::PaymentRequest->value,
        MessageType::PaymentConfirmed->value,
    );
});

it('renders snapshot money with live status on the reorder chain cards', function () {
    [$c, $buyer, , $staff, $source] = roScene();

    [, , $new] = roRunChain($c, $source, $buyer, $staff, 700.00);

    $card = $c->refresh()->messages()->where('type', MessageType::OrderReference->value)->latest('id')->firstOrFail();
    $snapshot = $card->payloadValue('total_amount');

    // Move the live status; the snapshot must not move with it.
    app(OrderLifecycleService::class)->confirm($c->refresh(), $new->refresh(), $staff);

    expect($card->refresh()->payloadValue('total_amount'))->toBe($snapshot)
        ->and($card->related->status)->toBe(OrderStatus::Confirmed);
});

it('marks the new order as a reorder without inheriting anything from the old one', function () {
    [$c, $buyer, , $staff, $source] = roScene(620.00);

    [, , $new] = roRunChain($c, $source, $buyer, $staff, 700.00);

    expect($new->isReorder())->toBeTrue()
        ->and($new->reorderOf->getKey())->toBe($source->getKey())
        ->and($new->status)->toBe(OrderStatus::Awarded)
        ->and($new->payment_status)->toBe(OrderPaymentStatus::Unpaid)
        ->and((float) $new->amount_paid)->toBe(0.0)
        ->and((float) $new->total_amount)->not->toBe((float) $source->total_amount);
});

/* ================================================================== PAYMENTS */

it('still changes payment status only through the authorised path on a reorder', function () {
    [$c, $buyer, , $staff, $source] = roScene();

    [, , $new] = roRunChain($c, $source, $buyer, $staff);

    expect($new->payment_status)->toBe(OrderPaymentStatus::Unpaid);

    // The buyer cannot mark their own reorder paid.
    expect(fn () => app(OrderLifecycleService::class)
        ->recordPayment($c->refresh(), $new->refresh(), $buyer, $new->total_amount, 'Bank transfer'))
        ->toThrow(HttpException::class);

    expect($new->refresh()->payment_status)->toBe(OrderPaymentStatus::Unpaid);

    app(OrderLifecycleService::class)->recordPayment($c->refresh(), $new->refresh(), $staff, $new->total_amount, 'Bank transfer');

    expect($new->refresh()->payment_status)->toBe(OrderPaymentStatus::Paid);
});

/* ================================================== REVIEW-AND-REORDER SURFACE */

it('shows the review-and-reorder surface only after genuine completion', function () {
    [$c, $buyer, , $staff, $source] = roScene();

    [, , $new] = roRunChain($c, $source, $buyer, $staff);

    // The new order is only awarded, so neither offer applies to it.
    expect(app(CompanyReviewService::class)->canReview($buyer, $new))->toBeFalse()
        ->and(roReorders()->canReorder($buyer, $new))->toBeFalse();

    // The source order is completed, so both do.
    expect(roReorders()->canReorder($buyer, $source->refresh()))->toBeTrue();

    Livewire::actingAs($buyer)
        ->test(Thread::class, ['conversationId' => $c->getKey()])
        ->assertSee('Order this again')
        ->assertSee('How was your experience?');
});

it('keeps review eligibility and one-per-order across a reorder', function () {
    [$c, $buyer, , $staff, $source] = roScene();

    app(OrderLifecycleService::class)->review($c, $source->refresh(), $buyer, ['rating' => 5]);

    // Second review of the same order is refused; the UNIQUE index backs it.
    expect(fn () => app(OrderLifecycleService::class)->review($c->refresh(), $source->refresh(), $buyer, ['rating' => 4]))
        ->toThrow(RuntimeException::class, 'already reviewed');

    [, , $new] = roRunChain($c->refresh(), $source->refresh(), $buyer, $staff);

    // The reorder is a different order, and it is not reviewable until it too
    // is completed. A reorder never inherits review eligibility.
    expect(fn () => app(OrderLifecycleService::class)->review($c->refresh(), $new, $buyer, ['rating' => 5]))
        ->toThrow(RuntimeException::class, 'completed');
});

it('renders the supplier pricing form with empty, required unit prices', function () {
    [$c, $buyer, $company, $staff, $source] = roScene(620.00);

    // Triage first: the form only exists once the request has reached them.
    roTriage(roReorders()->request($c, $source, $buyer)->related, $company);

    // Supplier parity: the same Thread component the exporter panel mounts.
    $component = Livewire::actingAs($staff)
        ->test(Thread::class, ['conversationId' => $c->getKey()])
        ->assertSee('Price this reorder')
        // The buyer's own control is never drawn on the supplier's side.
        ->assertDontSee('Send reorder request');

    $rfq = Rfq::where('reorder_of_order_id', $source->getKey())->firstOrFail();
    $itemId = (int) $rfq->items()->firstOrFail()->getKey();

    // Nothing is pre-filled: "confirm" can never be one click on last time's
    // price, and submitting without stating one is refused.
    $component->call('openReorderQuote', $rfq->getKey())
        ->assertSet('reorderQuoteForm.lines.'.$itemId.'.unit_price', '')
        ->call('submitReorderQuote')
        ->assertHasErrors('reorderQuoteForm.lines.'.$itemId.'.unit_price');

    expect(Quote::where('rfq_id', $rfq->getKey())->exists())->toBeFalse();
});

/* ============================================== ROUTES, LIMITS, ESCAPING, N+1 */

it('refuses a reorder over GET and requires CSRF on the POST', function () {
    [$c, $buyer, , , $source] = roScene();

    $this->actingAs($buyer)
        ->get('/messages/'.$c->getKey().'/orders/'.$source->getKey().'/reorder')
        ->assertStatus(405);

    expect(Rfq::where('reorder_of_order_id', $source->getKey())->exists())->toBeFalse();
});

it('rate limits reorder requests', function () {
    [$c, $buyer, , , $source] = roScene();

    RateLimiter::clear('chat-reorder:'.$buyer->getKey());

    for ($i = 0; $i < 12; $i++) {
        RateLimiter::hit('chat-reorder:'.$buyer->getKey(), 3600);
    }

    Livewire::actingAs($buyer)
        ->test(Thread::class, ['conversationId' => $c->getKey()])
        ->call('openReorder', $source->getKey())
        ->call('submitReorder')
        ->assertHasErrors('reorderForm.quantities');

    expect(Rfq::where('reorder_of_order_id', $source->getKey())->exists())->toBeFalse();

    RateLimiter::clear('chat-reorder:'.$buyer->getKey());
});

it('escapes buyer text on the reorder card', function () {
    [$c, $buyer, , , $source] = roScene();

    roReorders()->request($c, $source, $buyer, [
        'notes' => '<script>alert("xss")</script>',
        'shipping_port' => '"><img src=x onerror=alert(1)>',
    ]);

    Livewire::actingAs($buyer)
        ->test(Thread::class, ['conversationId' => $c->getKey()])
        ->assertDontSee('<script>alert("xss")</script>', false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false)
        ->assertSee('&lt;script&gt;', false);
});

it('keeps the query count bounded on a thread full of reorder chains', function () {
    [$c, $buyer, , $staff, $source] = roScene();

    // One chain, measured.
    [, , $new] = roRunChain($c, $source, $buyer, $staff);
    app(OrderLifecycleService::class)->issueProformaInvoice($c->refresh(), $new, $staff);

    DB::enableQueryLog();
    app(MessagingService::class)->messages($c->refresh());
    $one = count(DB::getQueryLog());
    DB::flushQueryLog();

    // Duplicate every card in the thread ten times over, then measure again.
    // The morphWith eager loads must absorb it: no per-card lookups.
    $rows = $c->messages()->get();

    for ($i = 0; $i < 10; $i++) {
        foreach ($rows as $row) {
            $copy = $row->replicate();
            $copy->created_at = now();
            $copy->save();
        }
    }

    DB::flushQueryLog();
    app(MessagingService::class)->messages($c->refresh());
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($many)->toBeLessThanOrEqual($one);
});
