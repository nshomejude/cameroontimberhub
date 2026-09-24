<?php

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\DeviceToken;
use App\Models\Dispute;
use App\Models\NotificationPreference;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\ChatCommerceService;
use App\Services\DisputeService;
use App\Services\OrderLifecycleService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/**
 * A real Order with a genuine buyer and supplier company, and a conversation
 * for that order (Order/Conversation/Dispute/Quote all wired the same way
 * the other API tests build them — see DisputeApiTest::apiDisputeOrder()).
 *
 * @return array{0: Order, 1: User, 2: Company, 3: User, 4: Conversation}
 */
function notificationApiOrder(): array
{
    $supplierCompany = Company::factory()->publiclyVisible()->create();
    $supplierUser = User::factory()->create();
    $supplierCompany->users()->attach($supplierUser);

    $buyer = User::factory()->create();

    $rfq = Rfq::factory()->approved()->create(['buyer_email' => $buyer->email, 'user_id' => $buyer->getKey()]);
    $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3',
    ]);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $supplierCompany->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $supplierCompany->getKey(),
        'rfq_company_id' => $routing->getKey(),
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'description' => 'Sawn timber',
        'quantity' => 100,
        'unit_price' => 185.00,
        'line_total' => Quote::lineTotal(100, 185.00),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->accept($quote, $buyer);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    $conversation = Conversation::factory()->create([
        'user_id' => $buyer->getKey(),
        'company_id' => $supplierCompany->getKey(),
        'order_id' => $order->getKey(),
    ]);

    ConversationParticipant::firstOrCreate(
        ['conversation_id' => $conversation->getKey(), 'user_id' => $supplierUser->getKey()],
        ['role' => ConversationParticipant::ROLE_SUPPLIER, 'company_id' => $supplierCompany->getKey()],
    );

    return [$order->fresh(), $buyer, $supplierCompany, $supplierUser, $conversation->fresh()];
}

/* ------------------------------------------------------- quote received */

it('notifies the buyer when a supplier submits a quote', function () {
    $supplierCompany = Company::factory()->publiclyVisible()->create();
    $supplierUser = User::factory()->create();
    $supplierCompany->users()->attach($supplierUser);

    $buyer = User::factory()->create();
    $rfq = Rfq::factory()->approved()->create(['buyer_email' => $buyer->email, 'user_id' => $buyer->getKey()]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3']);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $supplierCompany->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    $quote = app(QuoteService::class)->open($rfq, $supplierCompany);
    QuoteItem::create([
        'quote_id' => $quote->getKey(),
        'description' => 'Sapele sawn timber',
        'quantity' => 50,
        'unit' => 'm3',
        'unit_price' => 185.00,
        'line_total' => Quote::lineTotal(50, 185.00),
    ]);

    app(QuoteService::class)->submit($quote->fresh(), $supplierUser);

    expect($buyer->fresh()->unreadNotifications()->count())->toBe(1);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'quote_received')
        ->assertJsonPath('data.0.reference', $quote->fresh()->reference_code)
        ->assertJsonPath('data.0.read_at', null);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.count', 1);
});

/* -------------------------------------------------- order status changed */

it('notifies the buyer when the supplier advances the order status', function () {
    [$order, $buyer, , $supplierUser, $conversation] = notificationApiOrder();

    app(OrderLifecycleService::class)->confirm($conversation, $order, $supplierUser);

    expect($buyer->fresh()->unreadNotifications()->count())->toBe(1);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'order_status_changed')
        ->assertJsonPath('data.0.reference', $order->fresh()->reference_code);
});

/* ------------------------------------------------------- message received */

it('notifies the other participant of a new message, never the sender', function () {
    [, $buyer, , $supplierUser, $conversation] = notificationApiOrder();

    app(App\Services\MessagingService::class)->post($conversation, $buyer, 'Can you confirm the delivery port?');

    expect($supplierUser->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($buyer->fresh()->unreadNotifications()->count())->toBe(0);

    $this->actingAs($supplierUser, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'message_received');
});

/* --------------------------------------------------------- dispute reply */

it('notifies the other party when a dispute reply posts', function () {
    [$order, $buyer, $supplierCompany, $supplierUser] = notificationApiOrder();

    $dispute = app(DisputeService::class)->open($order, $buyer, App\Enums\DisputeCategory::Quality, 'Grade mismatch.');

    $dispute->forceFill([
        'respondent_user_id' => $supplierUser->getKey(),
        'respondent_company_id' => $supplierCompany->getKey(),
    ])->save();

    // reply() (via Dispute::respondentReply()) is only valid from
    // CounterpartyResponsePending — see DisputeApiTest's own reply tests.
    // The disputes API is buyer-only (`api.buyer` middleware) for this pass
    // — see routes/api.php — so the reply is posted by the buyer, and the
    // "other party" is the supplier's respondent user.
    app(DisputeService::class)->submitEvidence($dispute, $buyer, 'Supporting invoice attached.');

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->reference_code}/disputes/{$dispute->id}/reply", ['body' => 'We are looking into it.'])
        ->assertOk();

    expect($supplierUser->fresh()->unreadNotifications()->count())->toBe(1);

    $this->actingAs($supplierUser, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'dispute_reply');
});

/* -------------------------------------------------------------- read/read-all */

it('marks one notification read and decrements the unread count; 404s for someone else\'s', function () {
    [$order, $buyer] = notificationApiOrder();
    app(DisputeService::class)->open($order, $buyer, App\Enums\DisputeCategory::Other, 'Misc.');
    $buyer->notify(new App\Notifications\DisputeReplyNotification(
        Dispute::where('order_id', $order->getKey())->firstOrFail(),
        'Reply body',
    ));

    $notification = $buyer->fresh()->notifications()->firstOrFail();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('data.read_at', fn ($v) => $v !== null);

    expect($buyer->fresh()->unreadNotifications()->count())->toBe(0);

    $stranger = User::factory()->create();
    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/notifications/{$notification->id}/read")
        ->assertNotFound();
});

it('marks all of the caller\'s notifications read, leaving another user\'s untouched', function () {
    [$order, $buyer] = notificationApiOrder();
    $dispute = app(DisputeService::class)->open($order, $buyer, App\Enums\DisputeCategory::Other, 'Misc.');

    $buyer->notify(new App\Notifications\DisputeReplyNotification($dispute, 'One'));
    $buyer->notify(new App\Notifications\DisputeReplyNotification($dispute, 'Two'));

    $other = User::factory()->create();
    $other->notify(new App\Notifications\DisputeReplyNotification($dispute, 'Other user'));

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/notifications/read-all')
        ->assertOk();

    expect($buyer->fresh()->unreadNotifications()->count())->toBe(0)
        ->and($other->fresh()->unreadNotifications()->count())->toBe(1);
});

/* ------------------------------------------------------------- empty/guest */

it('returns an empty notification list and zero unread count for a fresh user', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data', []);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('data.count', 0);
});

it('requires auth on every notification route', function () {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
    $this->getJson('/api/v1/notifications/unread-count')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
    $this->postJson('/api/v1/notifications/00000000-0000-0000-0000-000000000000/read')->assertUnauthorized();
});

/* -------------------------------------------------------- detail route */

it('shows one notification in full detail; 404s for someone else\'s', function () {
    [$order, $buyer] = notificationApiOrder();
    app(DisputeService::class)->open($order, $buyer, App\Enums\DisputeCategory::Other, 'Misc.');
    $buyer->notify(new App\Notifications\DisputeReplyNotification(
        Dispute::where('order_id', $order->getKey())->firstOrFail(),
        'Reply body',
    ));

    $notification = $buyer->fresh()->notifications()->firstOrFail();

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/notifications/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('data.type', 'dispute_reply')
        ->assertJsonPath('data.icon', 'exclamation-triangle')
        ->assertJsonPath('data.tone', 'warning');

    $stranger = User::factory()->create();
    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/v1/notifications/{$notification->id}")
        ->assertNotFound();
});

/* ------------------------------------------------------- ExpoPushChannel */

it('Expo push posts one array-bodied request with the expected message shape', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]], 200)]);

    [$order, $buyer] = notificationApiOrder();
    DeviceToken::factory()->create(['user_id' => $buyer->getKey(), 'expo_push_token' => 'ExponentPushToken-live']);

    $user = $buyer;
    $user->notify(new App\Notifications\PaymentRequestedNotification($order));

    Http::assertSent(function ($request) use ($order) {
        $body = $request->data();

        return $request->url() === 'https://exp.host/--/api/v2/push/send'
            && is_array($body)
            && $body[0]['to'] === 'ExponentPushToken-live'
            && $body[0]['sound'] === 'default'
            && $body[0]['channelId'] === 'default'
            && $body[0]['data']['reference'] === $order->reference_code;
    });
});

it('prunes a device token when Expo reports DeviceNotRegistered', function () {
    Http::fake(['exp.host/*' => Http::response([
        'data' => [['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']]],
    ], 200)]);

    [$order, $buyer] = notificationApiOrder();
    $token = DeviceToken::factory()->create(['user_id' => $buyer->getKey(), 'expo_push_token' => 'ExponentPushToken-dead']);

    $buyer->notify(new App\Notifications\PaymentRequestedNotification($order));

    expect(DeviceToken::whereKey($token->getKey())->exists())->toBeFalse();
});

/* ----------------------------------------------------------- preferences */

it('does not create a notification row when the type is disabled', function () {
    $supplierCompany = Company::factory()->publiclyVisible()->create();
    $supplierUser = User::factory()->create();
    $supplierCompany->users()->attach($supplierUser);

    $buyer = User::factory()->create();
    NotificationPreference::forUser($buyer)->forceFill(['types' => ['quote_received' => false]])->save();

    $rfq = Rfq::factory()->approved()->create(['buyer_email' => $buyer->email, 'user_id' => $buyer->getKey()]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3']);
    RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $supplierCompany->getKey(), 'status' => 'sent', 'routed_at' => now()]);

    $quote = app(QuoteService::class)->open($rfq, $supplierCompany);
    QuoteItem::create(['quote_id' => $quote->getKey(), 'description' => 'Sawn', 'quantity' => 50, 'unit' => 'm3', 'unit_price' => 185.00, 'line_total' => Quote::lineTotal(50, 185.00)]);
    app(QuoteService::class)->submit($quote->fresh(), $supplierUser);

    expect($buyer->fresh()->notifications()->count())->toBe(0);
});

it('records the database row but never invokes push when channels.push is off', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => []], 200)]);

    [$order, $buyer] = notificationApiOrder();
    DeviceToken::factory()->create(['user_id' => $buyer->getKey(), 'expo_push_token' => 'ExponentPushToken-muted']);
    NotificationPreference::forUser($buyer)->forceFill(['channels' => ['push' => false, 'email' => true]])->save();

    $buyer->notify(new App\Notifications\PaymentRequestedNotification($order));

    expect($buyer->fresh()->notifications()->count())->toBe(1);
    Http::assertNothingSent();
});

/* ------------------------------------------------------------- new types */

it('notifies the supplier company when the buyer accepts their quote', function () {
    $supplierCompany = Company::factory()->publiclyVisible()->create();
    $supplierUser = User::factory()->create();
    $supplierCompany->users()->attach($supplierUser);

    $buyer = User::factory()->create();
    $rfq = Rfq::factory()->approved()->create(['buyer_email' => $buyer->email, 'user_id' => $buyer->getKey()]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3']);
    RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $supplierCompany->getKey(), 'status' => 'sent', 'routed_at' => now()]);

    $quote = Quote::factory()->submitted()->create(['rfq_id' => $rfq->getKey(), 'company_id' => $supplierCompany->getKey()]);
    QuoteItem::factory()->create(['quote_id' => $quote->getKey(), 'quantity' => 50, 'unit_price' => 185.00, 'line_total' => Quote::lineTotal(50, 185.00)]);
    $quote->load('items')->recalculateTotals()->save();

    $conversation = Conversation::factory()->create(['user_id' => $buyer->getKey(), 'company_id' => $supplierCompany->getKey()]);
    ConversationParticipant::firstOrCreate(
        ['conversation_id' => $conversation->getKey(), 'user_id' => $supplierUser->getKey()],
        ['role' => ConversationParticipant::ROLE_SUPPLIER, 'company_id' => $supplierCompany->getKey()],
    );

    app(ChatCommerceService::class)->acceptQuotation($conversation, $quote->fresh(), $buyer);

    expect($supplierUser->fresh()->unreadNotifications()->count())->toBe(1);
    $this->actingAs($supplierUser, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'quote_accepted');
});

it('notifies the buyer when the supplier requests payment', function () {
    [$order, $buyer, , $supplierUser, $conversation] = notificationApiOrder();

    app(OrderLifecycleService::class)->requestPayment($conversation, $order, $supplierUser, null, null);

    expect($buyer->fresh()->unreadNotifications()->count())->toBe(1);
    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.type', 'payment_requested');
});
