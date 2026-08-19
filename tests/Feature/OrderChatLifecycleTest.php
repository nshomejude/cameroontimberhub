<?php

use App\Enums\CompanyUserRole;
use App\Enums\MessageType;
use App\Enums\OrderDocumentKind;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Livewire\Messaging\Thread;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use App\Services\OrderLifecycleService;
use App\Services\OrderService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    RateLimiter::clear('order-lifecycle:1');
});

/* ------------------------------------------------------------------ helpers */
/* Prefixed `ol` so they never collide with the other feature files — Pest loads
   them all into one process. */

function olLifecycle(): OrderLifecycleService
{
    return app(OrderLifecycleService::class);
}

/**
 * A whole delivered-ready situation: buyer, supplier company + staff member,
 * an accepted quote, the resulting order, and the conversation they live in.
 *
 * @return array{0: Conversation, 1: User, 2: Company, 3: User, 4: Order}
 */
function olScene(): array
{
    $company = Company::factory()->publiclyVisible()->create();
    $staff = User::factory()->create(['email' => 'olstaff'.uniqid().'@example.com']);
    $company->users()->attach($staff, ['role' => CompanyUserRole::Owner->value, 'is_primary' => true]);

    $buyer = User::factory()->create([
        'email' => 'olbuyer'.uniqid().'@example.com',
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
        'unit_price' => 620.00,
        'line_total' => Quote::lineTotal(50, 620.00),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    $conversation = app(MessagingService::class)->start($buyer, $company);
    $commerce = app(ChatCommerceService::class);
    $commerce->issueQuotation($conversation, $quote->refresh(), $staff);
    $commerce->acceptQuotation($conversation, $quote->refresh(), $buyer);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    return [$conversation->refresh(), $buyer, $company, $staff, $order->refresh()];
}

/** Drive the order to a given status through the supplier-side service. */
function olAdvanceTo(Conversation $c, Order $order, User $staff, OrderStatus $target): Order
{
    $path = [
        [OrderStatus::Confirmed, fn () => olLifecycle()->confirm($c, $order->refresh(), $staff)],
        [OrderStatus::InProduction, fn () => olLifecycle()->startProduction($c, $order->refresh(), $staff)],
        [OrderStatus::Shipped, fn () => olLifecycle()->ship($c, $order->refresh(), $staff)],
        [OrderStatus::Delivered, fn () => olLifecycle()->deliver($c, $order->refresh(), $staff)],
    ];

    foreach ($path as [$status, $move]) {
        $move();

        if ($status === $target) {
            break;
        }
    }

    return $order->refresh();
}

function olCard(Conversation $c, MessageType $type): Message
{
    return $c->messages()->where('type', $type->value)->latest('id')->firstOrFail();
}

/* ================================================== SNAPSHOT VS LIVE CONTRACT */

it('renders snapshot money but live status on a lifecycle card', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->issueProformaInvoice($c, $order, $staff);
    // A shipment card too: it renders OrderStatus::description(), which is
    // unique per status, unlike the milestone labels that the trail always
    // prints for every step.
    olLifecycle()->updateTracking($c, $order, $staff, ['carrier' => 'Maersk Line']);

    $card = olCard($c, MessageType::ProformaInvoice);
    $snapshotTotal = $card->payloadValue('total_amount');
    $payloadBefore = $card->payload;

    // The card describes the awarded state.
    Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])
        ->assertSee(OrderStatus::Awarded->description())
        ->assertDontSee(OrderStatus::Confirmed->description());

    // Mutate the ORDER, not the message.
    olLifecycle()->confirm($c, $order->refresh(), $staff);

    // The live half moved...
    Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])
        ->assertSee(OrderStatus::Confirmed->description())
        ->assertDontSee(OrderStatus::Awarded->description());

    // ...and the message row did not.
    $card->refresh();
    expect($card->payload)->toEqual($payloadBefore)
        ->and($card->payloadValue('total_amount'))->toBe($snapshotTotal);
});

it('keeps the proforma money frozen even if the order row is edited', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->issueProformaInvoice($c, $order, $staff);
    $card = olCard($c, MessageType::ProformaInvoice);
    $snapshot = $card->payloadValue('total_amount');

    // A direct write to the order (which OrderService never does after
    // creation) must not rewrite the conversation's history.
    $order->forceFill(['total_amount' => '1.00'])->save();

    expect($card->refresh()->payloadValue('total_amount'))->toBe($snapshot)
        ->and($snapshot)->not->toBe('1.00');
});

it('posts at most one proforma invoice card per order', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    $first = olLifecycle()->issueProformaInvoice($c, $order, $staff);
    $second = olLifecycle()->issueProformaInvoice($c, $order, $staff);

    expect($second->getKey())->toBe($first->getKey())
        ->and($c->messages()->where('type', MessageType::ProformaInvoice->value)->count())->toBe(1);
});

/* ================================================== SUPPLIER-ONLY / BUYER-ONLY */

it('lets the supplier advance production and shipping', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->confirm($c, $order->refresh(), $staff);
    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);

    olLifecycle()->startProduction($c, $order->refresh(), $staff);
    expect($order->refresh()->status)->toBe(OrderStatus::InProduction);

    olLifecycle()->ship($c, $order->refresh(), $staff);
    expect($order->refresh()->status)->toBe(OrderStatus::Shipped);

    olLifecycle()->deliver($c, $order->refresh(), $staff);
    expect($order->refresh()->status)->toBe(OrderStatus::Delivered);
});

it('refuses the buyer every supplier-only lifecycle action', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    $attempts = [
        'confirm' => fn () => olLifecycle()->confirm($c, $order->refresh(), $buyer),
        'startProduction' => fn () => olLifecycle()->startProduction($c, $order->refresh(), $buyer),
        'ship' => fn () => olLifecycle()->ship($c, $order->refresh(), $buyer),
        'deliver' => fn () => olLifecycle()->deliver($c, $order->refresh(), $buyer),
        'proforma' => fn () => olLifecycle()->issueProformaInvoice($c, $order->refresh(), $buyer),
        'requestPayment' => fn () => olLifecycle()->requestPayment($c, $order->refresh(), $buyer),
        'recordPayment' => fn () => olLifecycle()->recordPayment($c, $order->refresh(), $buyer, 100),
        'updateTracking' => fn () => olLifecycle()->updateTracking($c, $order->refresh(), $buyer, ['carrier' => 'X']),
    ];

    foreach ($attempts as $name => $attempt) {
        expect($attempt)->toThrow(HttpException::class, 'Only the supplier on this conversation can do that.', $name);
    }

    // Nothing moved.
    expect($order->refresh()->status)->toBe(OrderStatus::Awarded)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::Unpaid);
});

it('refuses the supplier every buyer-only lifecycle action', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olAdvanceTo($c, $order, $staff, OrderStatus::Delivered);

    // Completion is the buyer's alone — a supplier who could close their own
    // order could manufacture review eligibility for themselves.
    expect(fn () => olLifecycle()->complete($c, $order->refresh(), $staff))
        ->toThrow(HttpException::class, 'Only the buyer on this conversation can do that.');

    expect(fn () => olLifecycle()->review($c, $order->refresh(), $staff, ['rating' => 5]))
        ->toThrow(HttpException::class, 'Only the buyer on this conversation can do that.');

    expect($order->refresh()->status)->toBe(OrderStatus::Delivered);
});

it('lets the buyer complete a delivered order', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olAdvanceTo($c, $order, $staff, OrderStatus::Delivered);

    olLifecycle()->complete($c, $order->refresh(), $buyer);

    expect($order->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->completed_at)->not->toBeNull();
});

/* ====================================================== NON-PARTICIPANT: 404 */

it('404s a non-participant on every lifecycle route and leaks nothing', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    $stranger = User::factory()->create(['email' => 'olstranger'.uniqid().'@example.com']);

    $routes = [
        ['post', route('chat.order.confirm', [$c, $order])],
        ['post', route('chat.order.ship', [$c, $order])],
        ['post', route('chat.order.complete', [$c, $order])],
        ['post', route('chat.order.proforma', [$c, $order])],
        ['post', route('chat.order.payment.record', [$c, $order]), ['amount' => 10]],
        ['get', route('chat.order.proforma.sheet', [$c, $order])],
    ];

    foreach ($routes as $route) {
        $response = $this->actingAs($stranger)->{$route[0]}($route[1], $route[2] ?? []);

        $response->assertNotFound();
        // No reference code, no supplier name, no buyer name in the body.
        expect($response->getContent())
            ->not->toContain($order->reference_code)
            ->not->toContain($company->name);
    }
});

it('404s an order id that belongs to another conversation', function () {
    [$c1, $buyer1, $company1, $staff1, $order1] = olScene();
    [$c2, $buyer2, $company2, $staff2, $order2] = olScene();

    // staff2 is a legitimate participant on c2 but order1 is not theirs.
    expect(fn () => olLifecycle()->threadOrder($c2, $order1->getKey()))
        ->toThrow(HttpException::class);

    $this->actingAs($staff2)
        ->post(route('chat.order.confirm', [$c2, $order1]))
        ->assertNotFound();
});

/* ==================================================== ILLEGAL TRANSITIONS */

it('refuses to ship an order that was never confirmed', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    expect($order->status)->toBe(OrderStatus::Awarded);

    expect(fn () => olLifecycle()->ship($c, $order, $staff))
        ->toThrow(RuntimeException::class, 'Illegal order transition awarded -> shipped');

    expect($order->refresh()->status)->toBe(OrderStatus::Awarded)
        ->and($order->shipped_at)->toBeNull();
});

it('refuses to complete a cancelled order', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    app(OrderService::class)->cancel($order, 'Buyer withdrew.', $staff);
    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);

    expect(fn () => olLifecycle()->complete($c, $order->refresh(), $buyer))
        ->toThrow(RuntimeException::class, 'Illegal order transition cancelled -> completed');
});

it('refuses to deliver before shipping', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->confirm($c, $order->refresh(), $staff);

    expect(fn () => olLifecycle()->deliver($c, $order->refresh(), $staff))
        ->toThrow(RuntimeException::class, 'Illegal order transition confirmed -> delivered');
});

it('backs the order status vocabulary with a database CHECK constraint', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    expect(fn () => DB::table('orders')->where('id', $order->getKey())->update(['status' => 'teleported']))
        ->toThrow(QueryException::class);
});

/* ============================================================== PAYMENTS */

it('only changes payment status through the authorised path', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    expect($order->payment_status)->toBe(OrderPaymentStatus::Unpaid)
        ->and((float) $order->amount_paid)->toBe(0.0);

    // Buyer cannot.
    expect(fn () => olLifecycle()->recordPayment($c, $order->refresh(), $buyer, $order->total_amount))
        ->toThrow(HttpException::class);
    expect($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Unpaid);

    // Supplier can, and it is a partial payment when it is one.
    olLifecycle()->recordPayment($c, $order->refresh(), $staff, 100.00, 'Bank transfer');

    expect($order->refresh()->payment_status)->toBe(OrderPaymentStatus::PartiallyPaid)
        ->and((float) $order->amount_paid)->toBe(100.0)
        ->and($order->payment_method)->toBe('Bank transfer')
        ->and($order->payment_recorded_at)->not->toBeNull();

    // Paying the balance flips it to Paid.
    olLifecycle()->recordPayment($c, $order->refresh(), $staff, $order->total_amount);
    expect($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Paid);
});

it('refuses a recorded payment larger than the order total', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    expect(fn () => olLifecycle()->recordPayment($c, $order, $staff, (float) $order->total_amount + 1))
        ->toThrow(RuntimeException::class, 'A recorded payment cannot exceed the order total.');

    expect($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Unpaid);
});

it('never offers a payment collection control in the thread', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->requestPayment($c, $order, $staff);

    $html = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();

    // The mockup's "Pay Now" and any credential field must not exist.
    expect($html)
        ->not->toContain('Pay Now')
        ->not->toContain('card number')
        ->not->toContain('cardnumber')
        ->not->toContain('cvv')
        ->and($html)->toContain('does not process payments');
});

it('shows supplier-only controls to the supplier and never to the buyer', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->requestPayment($c, $order, $staff);
    olLifecycle()->updateTracking($c, $order->refresh(), $staff, ['carrier' => 'Maersk Line']);

    $supplierHtml = Livewire::actingAs($staff)->test(Thread::class, ['conversationId' => $c->getKey()])->html();
    $buyerHtml = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();

    // Supplier sees the write controls for the stage the order is actually in.
    expect($supplierHtml)
        ->toContain('Record a payment received')
        ->toContain('Shipment details')
        ->toContain('Confirm order');

    // The buyer sees none of them, on either card.
    expect($buyerHtml)
        ->not->toContain('Record a payment received')
        ->not->toContain('Shipment details')
        ->not->toContain('Confirm order')
        ->not->toContain('Attach a document');
});

it('offers completion to the buyer only, once the order is delivered', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olAdvanceTo($c, $order, $staff, OrderStatus::Shipped);

    // Not yet delivered: nobody is offered completion.
    $early = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();
    expect($early)->not->toContain('Confirm receipt and close this order');

    olLifecycle()->deliver($c, $order->refresh(), $staff);

    $buyerHtml = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();
    $supplierHtml = Livewire::actingAs($staff)->test(Thread::class, ['conversationId' => $c->getKey()])->html();

    expect($buyerHtml)->toContain('Confirm receipt and close this order')
        ->and($supplierHtml)->not->toContain('Confirm receipt and close this order');
});

/* ============================================================== TRACKING */

it('renders tracking fields only when they are populated', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->confirm($c, $order->refresh(), $staff);
    olLifecycle()->ship($c, $order->refresh(), $staff);

    // Nothing entered yet: no shipment facts at all.
    expect($order->refresh()->shipmentFacts())->toBe([]);

    $html = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();

    // No empty labels, and none of the mockup's placeholder copy.
    expect($html)
        ->not->toContain('Tracking number')
        ->not->toContain('Carrier')
        ->not->toContain('Vessel')
        ->not->toContain('To be assigned');

    // Enter two facts only.
    olLifecycle()->updateTracking($c, $order->refresh(), $staff, [
        'carrier' => 'Maersk Line',
        'tracking_number' => 'MSKU123456789',
    ]);

    $facts = $order->refresh()->shipmentFacts();
    expect($facts)->toHaveKey('Carrier')
        ->toHaveKey('Tracking number')
        ->not->toHaveKey('Vessel')
        ->not->toHaveKey('Container')
        ->and($facts)->toHaveCount(2);

    $html = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();

    expect($html)
        ->toContain('Maersk Line')
        ->toContain('MSKU123456789')
        // Still absent, because still empty.
        ->not->toContain('>Vessel<')
        ->not->toContain('>Container<');
});

it('fabricates no live tracking feed, map or event history', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->confirm($c, $order->refresh(), $staff);
    olLifecycle()->ship($c, $order->refresh(), $staff, [
        'carrier' => 'Maersk Line',
        'tracking_url' => 'https://example.test/track/MSKU1',
    ]);

    $html = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();

    // None of the mockup's invented live-tracking furniture may appear.
    expect($html)
        ->not->toContain('LIVE TRACKING')
        ->not->toContain('Current Location')
        ->not->toContain('At Sea')
        ->not->toContain('Last updated')
        ->not->toContain('Full Tracking History')
        ->not->toContain('<iframe')
        ->and($html)->toContain('does not track shipments');
});

it('refuses a non-http tracking link at the model boundary', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    // Even if something got past validation, the model refuses to emit it.
    $order->forceFill(['tracking_url' => 'javascript:alert(1)'])->save();
    expect($order->refresh()->trackingLink())->toBeNull();

    $order->forceFill(['tracking_url' => 'https://example.test/track/1'])->save();
    expect($order->refresh()->trackingLink())->toBe('https://example.test/track/1');
});

it('derives the progress trail from real timestamps only', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->confirm($c, $order->refresh(), $staff);

    $milestones = collect($order->refresh()->milestones())->keyBy(fn ($m) => $m['status']->value);

    expect($milestones['awarded']['reached'])->toBeTrue()
        ->and($milestones['confirmed']['reached'])->toBeTrue()
        ->and($milestones['shipped']['reached'])->toBeFalse()
        // Nothing is projected: an unreached milestone has NO date.
        ->and($milestones['shipped']['at'])->toBeNull()
        ->and($milestones['delivered']['at'])->toBeNull();
});

/* ============================================================= DOCUMENTS */

it('stores order documents on the private disk with no public url', function () {
    Storage::fake('documents');

    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->attachDocuments(
        $c,
        $order,
        $staff,
        [UploadedFile::fake()->create('bill-of-lading.pdf', 120, 'application/pdf')],
        OrderDocumentKind::BillOfLading,
    );

    $document = OrderDocument::where('order_id', $order->getKey())->firstOrFail();

    expect($document->disk)->toBe('documents')
        ->and($document->kind)->toBe(OrderDocumentKind::BillOfLading)
        ->and($document->original_filename)->toBe('bill-of-lading.pdf');

    Storage::disk('documents')->assertExists($document->storage_path);

    // The generated path carries no client-supplied component.
    expect($document->storage_path)->toStartWith('orders/'.$order->getKey().'/')
        ->not->toContain('bill-of-lading');

    // The bytes are not under the public root.
    expect(file_exists(public_path($document->storage_path)))->toBeFalse();
});

it('requires authorization to download an order document', function () {
    Storage::fake('documents');

    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->attachDocuments($c, $order, $staff, [
        UploadedFile::fake()->create('packing-list.pdf', 50, 'application/pdf'),
    ], OrderDocumentKind::PackingList);

    $document = OrderDocument::where('order_id', $order->getKey())->firstOrFail();
    $url = route('order-documents.download', $document);

    // Both participants may.
    $this->actingAs($buyer)->get($url)->assertOk();
    $this->actingAs($staff)->get($url)->assertOk();

    // A stranger may not, and gets 404 rather than 403 so existence stays secret.
    $stranger = User::factory()->create(['email' => 'oldocstranger'.uniqid().'@example.com']);
    $this->actingAs($stranger)->get($url)->assertNotFound();

    // Nor may a guest.
    auth()->logout();
    $this->get($url)->assertRedirect();
});

it('rejects a disallowed document type and size', function () {
    Storage::fake('documents');

    [$c, $buyer, $company, $staff, $order] = olScene();

    // Executable disguised by extension: the sniffed MIME type fails.
    expect(fn () => olLifecycle()->attachDocuments($c, $order, $staff, [
        UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload'),
    ]))->toThrow(RuntimeException::class, 'Only PDF, JPG, PNG and WEBP');

    // Too large.
    expect(fn () => olLifecycle()->attachDocuments($c, $order, $staff, [
        UploadedFile::fake()->create('huge.pdf', 20 * 1024, 'application/pdf'),
    ]))->toThrow(RuntimeException::class, '15 MB or smaller');

    expect(OrderDocument::where('order_id', $order->getKey())->count())->toBe(0);
});

it('refuses a buyer attaching documents to the order', function () {
    Storage::fake('documents');

    [$c, $buyer, $company, $staff, $order] = olScene();

    expect(fn () => olLifecycle()->attachDocuments($c, $order, $buyer, [
        UploadedFile::fake()->create('forged.pdf', 10, 'application/pdf'),
    ]))->toThrow(HttpException::class);

    expect(OrderDocument::where('order_id', $order->getKey())->count())->toBe(0);
});

it('lists documents live in the card rather than snapshotting them', function () {
    Storage::fake('documents');

    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->attachDocuments($c, $order, $staff, [
        UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
    ]);

    $card = olCard($c, MessageType::OrderDocuments);
    $payloadBefore = $card->payload;

    olLifecycle()->attachDocuments($c, $order, $staff, [
        UploadedFile::fake()->create('second.pdf', 10, 'application/pdf'),
    ]);

    // Still one card, unchanged payload...
    expect($c->messages()->where('type', MessageType::OrderDocuments->value)->count())->toBe(1)
        ->and($card->refresh()->payload)->toEqual($payloadBefore);

    // ...but it now lists both files.
    $html = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();
    expect($html)->toContain('first.pdf')->toContain('second.pdf');
});

/* ================================================== POD / DELIVERY RECORDING */

it('records real delivery facts and proof files, omitting what is absent', function () {
    Storage::fake('documents');

    [$c, $buyer, $company, $staff, $order] = olScene();

    olAdvanceTo($c, $order, $staff, OrderStatus::Shipped);

    olLifecycle()->deliver(
        $c,
        $order->refresh(),
        $staff,
        'Mr. Alain Mbarga',
        null, // no location recorded
        [UploadedFile::fake()->image('pod.jpg')],
    );

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->delivered_to_name)->toBe('Mr. Alain Mbarga')
        ->and($order->delivery_location)->toBeNull();

    $facts = $order->deliveryFacts();
    expect($facts)->toHaveKey('Received by')
        ->toHaveKey('Delivered on')
        // Absent field yields NO key, so no empty label renders.
        ->not->toHaveKey('Delivery location');

    expect($order->documents()->where('kind', OrderDocumentKind::ProofOfDelivery->value)->count())->toBe(1);

    $html = Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();
    expect($html)->toContain('Mr. Alain Mbarga')->toContain('Proof of delivery');
});

/* ================================================================ CSRF / GET */

it('changes no state on a GET request', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    // Every mutating lifecycle route is POST-only.
    foreach (['chat.order.confirm', 'chat.order.ship', 'chat.order.complete', 'chat.order.review'] as $name) {
        $this->actingAs($staff)->get(route($name, [$c, $order]))->assertMethodNotAllowed();
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Awarded);
});

/* ================================================================ THROTTLING */

it('rate limits lifecycle actions from the Livewire surface', function () {
    [$c, $buyer, $company, $staff, $order] = olScene();

    RateLimiter::clear('order-lifecycle:'.$staff->getKey());

    // Burn the per-minute budget (20).
    for ($i = 0; $i < 20; $i++) {
        RateLimiter::hit('order-lifecycle:'.$staff->getKey(), 60);
    }

    Livewire::actingAs($staff)->test(Thread::class, ['conversationId' => $c->getKey()])
        ->call('confirmOrder', $order->getKey())
        ->assertHasErrors('body');

    expect($order->refresh()->status)->toBe(OrderStatus::Awarded);
});

/* ============================================================= QUERY BOUNDS */

it('keeps a thread with many lifecycle cards to a bounded query count', function () {
    Storage::fake('documents');

    [$c, $buyer, $company, $staff, $order] = olScene();

    olLifecycle()->issueProformaInvoice($c, $order, $staff);
    olLifecycle()->requestPayment($c, $order, $staff);
    olLifecycle()->recordPayment($c, $order, $staff, 100);
    olLifecycle()->confirm($c, $order->refresh(), $staff);
    olLifecycle()->startProduction($c, $order->refresh(), $staff);
    olLifecycle()->attachDocuments($c, $order->refresh(), $staff, [
        UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
    ]);

    // Ten more shipment cards, all pointing at the same order.
    for ($i = 0; $i < 10; $i++) {
        olLifecycle()->updateTracking($c, $order->refresh(), $staff, ['carrier' => 'Carrier '.$i]);
    }

    $baseline = null;

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $c->getKey()])->html();
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The morphWith eager-load means the count does not scale with the number
    // of cards. A hard ceiling catches an N+1 reintroduced later.
    expect($many)->toBeLessThan(45);
});
