<?php

use App\Enums\CompanyUserRole;
use App\Enums\ConversationTopic;
use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Livewire\Messaging\Inbox;
use App\Livewire\Messaging\Thread;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\MessagingService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/* ------------------------------------------------------------------ helpers */
/* Pest loads every test file into one process, so these names are prefixed to
   stay clear of the OrderTest / BuyerDashboardTest helpers. */

function msgBuyer(array $attributes = []): User
{
    return User::factory()->create($attributes + ['email' => 'msgbuyer'.uniqid().'@example.com']);
}

/** A verified company with one staff member on the company_user pivot. */
function msgCompany(): array
{
    $company = Company::factory()->publiclyVisible()->create();
    $staff = User::factory()->create(['email' => 'staff'.uniqid().'@example.com']);
    $company->users()->attach($staff, ['role' => CompanyUserRole::Owner->value, 'is_primary' => true]);

    return [$company, $staff];
}

function msgService(): MessagingService
{
    return app(MessagingService::class);
}

/** A thread between a fresh buyer and a fresh company. */
function msgThread(): array
{
    [$company, $staff] = msgCompany();
    $buyer = msgBuyer();

    return [msgService()->start($buyer, $company), $buyer, $company, $staff];
}

/** An awarded order owned by $buyer against $company. */
function msgOrder(User $buyer, Company $company): Order
{
    $rfq = Rfq::factory()->approved()->create([
        'user_id' => $buyer->getKey(),
        'buyer_email' => $buyer->email,
    ]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3']);

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
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'description' => 'Premium Sapele Lumber (KD)',
        'quantity' => 50,
        'unit_price' => 620.00,
        'line_total' => Quote::lineTotal(50, 620.00),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->accept($quote, $buyer);

    return Order::where('quote_id', $quote->getKey())->firstOrFail();
}

/* ------------------------------------------------------------ participants */

it('lets the buyer read and post in their own conversation', function () {
    [$conversation, $buyer] = msgThread();

    $this->actingAs($buyer)
        ->get(route('account.messages.show', $conversation))
        ->assertOk()
        ->assertSee('Type a message');

    msgService()->post($conversation, $buyer, 'Do you have 50 cubic metres available?');

    expect($conversation->messages()->where('body', 'Do you have 50 cubic metres available?')->exists())->toBeTrue();
});

it('lets a member of the supplier company read and reply', function () {
    [$conversation, $buyer, $company, $staff] = msgThread();

    msgService()->post($conversation, $buyer, 'Buyer question here');
    $reply = msgService()->post($conversation, $staff, 'Supplier answer here');

    // The supplier's message is attributed to the company, not just the person.
    expect((int) $reply->sender_company_id)->toBe((int) $company->getKey());

    expect(msgService()->allows($staff, $conversation))->toBeTrue();
});

it('404s a non-participant on the thread route and leaks no content', function () {
    [$conversation, $buyer] = msgThread();

    msgService()->post($conversation, $buyer, 'CONFIDENTIAL-PRICING-DETAIL');

    $stranger = msgBuyer();

    $response = $this->actingAs($stranger)->get(route('account.messages.show', $conversation));

    $response->assertNotFound();
    $response->assertDontSee('CONFIDENTIAL-PRICING-DETAIL');
    $response->assertDontSee($conversation->company->name);
});

it('refuses a non-participant posting to a conversation', function () {
    [$conversation] = msgThread();

    $this->actingAs(msgBuyer())
        ->post(route('account.messages.store', $conversation), ['body' => 'let me in'])
        ->assertNotFound();

    expect($conversation->messages()->where('body', 'let me in')->exists())->toBeFalse();
});

it('scopes supplier access to conversations of their own company', function () {
    [$conversationA, , $companyA, $staffA] = msgThread();
    [$conversationB] = msgThread();

    // staffA belongs to companyA only.
    expect(msgService()->allows($staffA, $conversationA))->toBeTrue();
    expect(msgService()->allows($staffA, $conversationB))->toBeFalse();

    $visible = Conversation::query()->forSupplier($staffA)->pluck('id')->all();

    expect($visible)->toContain($conversationA->getKey())
        ->and($visible)->not->toContain($conversationB->getKey());
});

it('blocks conversation id enumeration through the service resolver', function () {
    [, , , $staffA] = msgThread();
    [$conversationB] = msgThread();

    expect(fn () => msgService()->find($staffA, $conversationB->getKey()))
        ->toThrow(ModelNotFoundException::class);
});

it('serves the supplier side inside the exporter panel', function () {
    [$conversation, $buyer, , $staff] = msgThread();

    msgService()->post($conversation, $buyer, 'Do you still have stock for July?');

    $this->actingAs($staff)
        ->get('/dashboard/messages?conversation='.$conversation->getKey())
        ->assertOk()
        ->assertSee('Do you still have stock for July?');
});

it('does not open another company\'s thread in the exporter panel', function () {
    [, , , $staffA] = msgThread();
    [$conversationB, $buyerB] = msgThread();

    msgService()->post($conversationB, $buyerB, 'OTHER-COMPANY-SECRET');

    $this->actingAs($staffA)
        ->get('/dashboard/messages?conversation='.$conversationB->getKey())
        ->assertOk()
        ->assertDontSee('OTHER-COMPANY-SECRET');
});

/* ------------------------------------------------------------------ unread */

it('computes real unread counts and clears them on read', function () {
    [$conversation, $buyer, , $staff] = msgThread();

    // The buyer starts even (start() stamps their last_read_at).
    expect(msgService()->totalUnread($buyer))->toBe(0);

    msgService()->post($conversation, $staff, 'One');
    msgService()->post($conversation, $staff, 'Two');

    expect(msgService()->unreadCounts($buyer, [$conversation->getKey()]))
        ->toBe([$conversation->getKey() => 2]);

    // A sender never has their own message unread.
    expect(msgService()->totalUnread($staff))->toBe(0);

    msgService()->markRead($conversation, $buyer);

    expect(msgService()->totalUnread($buyer))->toBe(0);
});

it('shows the unread badge in the inbox and clears it when the thread is opened', function () {
    [$conversation, $buyer, , $staff] = msgThread();

    msgService()->post($conversation, $staff, 'Please confirm the volume');

    Livewire::actingAs($buyer)
        ->test(Inbox::class, ['scope' => 'buyer'])
        ->assertSee('Please confirm the volume')
        ->assertSee('1 unread');

    Livewire::actingAs($buyer)->test(Thread::class, ['conversationId' => $conversation->getKey()]);

    expect(msgService()->totalUnread($buyer))->toBe(0);
});

/* ------------------------------------------------------------ typed messages */

it('persists and renders text, system and order-reference messages', function () {
    [$company, $staff] = msgCompany();
    $buyer = msgBuyer();
    $order = msgOrder($buyer, $company);

    $conversation = msgService()->start($buyer, $company);

    msgService()->post($conversation, $buyer, 'Plain prose message');
    msgService()->postSystem($conversation, 'A platform note');
    $card = msgService()->postOrderReference($conversation, $staff, $order);

    expect($card->type)->toBe(MessageType::OrderReference)
        ->and($card->related_id)->toBe($order->getKey())
        ->and($card->payloadValue('reference_code'))->toBe($order->reference_code)
        // Money is snapshotted into the payload, not read live.
        ->and($card->payloadValue('total_amount'))->toBe((string) $order->total_amount);

    $this->actingAs($buyer)
        ->get(route('account.messages.show', $conversation))
        ->assertOk()
        ->assertSee('Plain prose message')
        ->assertSee('A platform note')
        ->assertSee('Order reference')
        ->assertSee($order->reference_code);
});

it('renders the order-reference card with LIVE order status', function () {
    [$company, $staff] = msgCompany();
    $buyer = msgBuyer();
    $order = msgOrder($buyer, $company);

    $conversation = msgService()->start($buyer, $company);
    msgService()->postOrderReference($conversation, $staff, $order);

    $this->actingAs($buyer)
        ->get(route('account.messages.show', $conversation))
        ->assertOk()
        ->assertSee(OrderStatus::Awarded->label())
        ->assertDontSee(OrderStatus::Shipped->label());

    // Move the underlying order. The message row is untouched.
    $order->forceFill(['status' => OrderStatus::Shipped, 'shipped_at' => now()])->save();

    $this->actingAs($buyer)
        ->get(route('account.messages.show', $conversation))
        ->assertOk()
        ->assertSee(OrderStatus::Shipped->label());
});

it('renders the live milestone trail on the order-status card', function () {
    [$company] = msgCompany();
    $buyer = msgBuyer();
    $order = msgOrder($buyer, $company);

    $conversation = msgService()->start($buyer, $company);
    msgService()->postOrderStatus($conversation, $order);

    $this->actingAs($buyer)
        ->get(route('account.messages.show', $conversation))
        ->assertOk()
        // Nothing after Awarded has happened, so it must read Pending.
        ->assertSee('Pending');

    $order->forceFill(['status' => OrderStatus::Confirmed, 'confirmed_at' => now()])->save();

    $this->actingAs($buyer)
        ->get(route('account.messages.show', $conversation))
        ->assertSee(OrderStatus::Confirmed->label());
});

/* --------------------------------------------------------- message actions */

it('lets a sender delete their own message but not the other side\'s', function () {
    [$conversation, $buyer, , $staff] = msgThread();

    $mine = msgService()->post($conversation, $buyer, 'Delete me');
    $theirs = msgService()->post($conversation, $staff, 'Not yours to delete');

    Livewire::actingAs($buyer)
        ->test(Thread::class, ['conversationId' => $conversation->getKey()])
        ->call('deleteMessage', $mine->getKey());

    expect(Message::find($mine->getKey()))->toBeNull()
        ->and(Message::withTrashed()->find($mine->getKey()))->not->toBeNull();

    expect(fn () => msgService()->delete($theirs, $buyer))
        ->toThrow(HttpException::class);
});

/* -------------------------------------------------------------- rate limit */

it('rate-limits posting', function () {
    [$conversation, $buyer] = msgThread();

    $last = null;

    for ($i = 0; $i < 25; $i++) {
        $last = $this->actingAs($buyer)
            ->post(route('account.messages.store', $conversation), ['body' => 'spam '.$i]);
    }

    expect($last->getStatusCode())->toBe(429);
    expect($conversation->messages()->where('type', MessageType::Text->value)->count())->toBeLessThan(25);
});

/* --------------------------------------------------------------------- XSS */

it('escapes message bodies rather than rendering them as HTML', function () {
    [$conversation, $buyer] = msgThread();

    $payload = '<script>alert("xss")</script>';

    $this->actingAs($buyer)->post(route('account.messages.store', $conversation), ['body' => $payload]);

    $response = $this->actingAs($buyer)->get(route('account.messages.show', $conversation));

    $response->assertOk();
    $response->assertDontSee($payload, escape: false);
    $response->assertSee('&lt;script&gt;', escape: false);
});

/* ------------------------------------------------------------------ noindex */

it('marks the inbox and thread noindex', function () {
    [$conversation, $buyer] = msgThread();

    $this->actingAs($buyer)->get(route('account.messages'))
        ->assertOk()->assertSee('noindex, nofollow', escape: false);

    $this->actingAs($buyer)->get(route('account.messages.show', $conversation))
        ->assertOk()->assertSee('noindex, nofollow', escape: false);
});

/* ---------------------------------------------------------------- query use */

it('renders the inbox with a bounded query count regardless of thread volume', function () {
    $buyer = msgBuyer();

    foreach (range(1, 8) as $i) {
        [$company, $staff] = msgCompany();
        $conversation = msgService()->start($buyer, $company);
        msgService()->post($conversation, $staff, 'Message on thread '.$i);
    }

    DB::enableQueryLog();

    $this->actingAs($buyer)->get(route('account.messages'))->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Eight conversations must not cost eight extra lookups: the counterparty,
    // the last message and every unread count are batched.
    expect($count)->toBeLessThan(30);
});

it('renders a long thread with a bounded query count', function () {
    [$conversation, $buyer, $company, $staff] = msgThread();
    $order = msgOrder($buyer, $company);

    foreach (range(1, 15) as $i) {
        msgService()->post($conversation, $i % 2 ? $staff : $buyer, 'Line '.$i);
    }

    foreach (range(1, 4) as $i) {
        msgService()->postOrderReference($conversation, $staff, $order);
    }

    DB::enableQueryLog();

    $this->actingAs($buyer)->get(route('account.messages.show', $conversation))->assertOk();

    $few = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Twenty more order cards, all pointing at the same order.
    foreach (range(1, 20) as $i) {
        msgService()->postOrderReference($conversation, $staff, $order);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($buyer)->get(route('account.messages.show', $conversation))->assertOk();

    $many = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The real guarantee is that the count does NOT move with the number of
    // cards: five times the cards must cost the same queries, because
    // MessagingService::messages() batches the polymorphic `related` load and
    // everything hanging off it.
    //
    // The ceiling is a secondary backstop. It sits at 34 rather than the
    // original 30 because Phase 3 added three batched eager-loads to the Order
    // morph (items, documents, review) so the lifecycle cards do not each go
    // back to the database — a constant +2 here, and the saving grows with
    // every lifecycle card in a real thread.
    // Twenty extra cards may cost at most one extra query (the read-state
    // write differs between a first and a subsequent visit); an N+1 would show
    // up as twenty.
    expect($many - $few)->toBeLessThanOrEqual(1)
        ->and($many)->toBeLessThan(34);
});

/* ------------------------------------------------------ starting a thread */

it('links the right records when a conversation starts from a product context', function () {
    [$company] = msgCompany();
    $buyer = msgBuyer();
    $product = Product::factory()->create(['company_id' => $company->getKey()]);

    $this->actingAs($buyer)
        ->post(route('account.messages.start'), [
            'company' => $company->slug,
            'topic' => ConversationTopic::Product->value,
            'product' => $product->slug,
        ])
        ->assertRedirect();

    $conversation = Conversation::where('user_id', $buyer->getKey())->firstOrFail();

    expect((int) $conversation->company_id)->toBe((int) $company->getKey())
        ->and((int) $conversation->product_id)->toBe((int) $product->getKey())
        ->and($conversation->topic)->toBe(ConversationTopic::Product);

    // The opening system note and the product card are both real messages.
    expect($conversation->messages()->where('type', MessageType::System->value)->exists())->toBeTrue()
        ->and($conversation->messages()->where('type', MessageType::ProductReference->value)->exists())->toBeTrue();
});

it('reuses the existing thread instead of creating duplicates', function () {
    [$company] = msgCompany();
    $buyer = msgBuyer();

    $first = msgService()->start($buyer, $company);
    $second = msgService()->start($buyer, $company);

    expect($second->getKey())->toBe($first->getKey())
        ->and(Conversation::where('user_id', $buyer->getKey())->count())->toBe(1);
});

it('will not attach an order the buyer does not own', function () {
    [$company] = msgCompany();
    $victim = msgBuyer();
    $attacker = msgBuyer();

    $order = msgOrder($victim, $company);

    $this->actingAs($attacker)
        ->post(route('account.messages.start'), ['company' => $company->slug, 'order' => $order->getKey()])
        ->assertRedirect();

    $conversation = Conversation::where('user_id', $attacker->getKey())->firstOrFail();

    expect($conversation->order_id)->toBeNull();
});
