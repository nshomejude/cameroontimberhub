<?php

use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\SupportTicketOpenedNotification;
use App\Notifications\SupportTicketStaffReplyNotification;
use App\Notifications\SupportTicketUserReplyNotification;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

function supportStaff(string $role = 'admin'): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

function supportOpen(User $user, array $overrides = []): string
{
    return test()->actingAs($user, 'sanctum')
        ->postJson('/api/v1/support/tickets', array_merge([
            'subject' => 'Cannot upload documents',
            'category' => 'technical',
            'body' => 'The upload spinner never stops.',
        ], $overrides))
        ->assertCreated()
        ->json('data.reference');
}

/** @return array{0: Order, 1: User, 2: Company} */
function supportOrder(): array
{
    $supplierCompany = Company::factory()->publiclyVisible()->create();
    $buyer = User::factory()->create();

    $rfq = Rfq::factory()->approved()->create(['buyer_email' => $buyer->email, 'user_id' => $buyer->getKey()]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3']);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $supplierCompany->getKey(),
        'status' => 'sent', 'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $supplierCompany->getKey(), 'rfq_company_id' => $routing->getKey(),
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(), 'description' => 'Sawn timber', 'quantity' => 100,
        'unit_price' => 185.00, 'line_total' => Quote::lineTotal(100, 185.00),
    ]);
    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->accept($quote, $buyer);

    return [Order::where('quote_id', $quote->getKey())->firstOrFail(), $buyer, $supplierCompany];
}

/* ------------------------------------------------------------ contact */

it('exposes public contact details without auth', function () {
    $this->getJson('/api/v1/contact')
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'organisation', 'phones', 'whatsapp', 'emails', 'hours' => [['days', 'time']],
            'hours_note', 'address', 'website',
        ]])
        ->assertJsonPath('data.organisation', config('contact.organisation'))
        ->assertJsonPath('data.emails.0', config('contact.emails.0'));
});

/* --------------------------------------------------------------- user */

it('creates a ticket with the first message and notifies staff', function () {
    Notification::fake();
    $staff = supportStaff('admin');
    $moderator = supportStaff('moderator');
    $user = User::factory()->create();

    $res = $this->actingAs($user, 'sanctum')->postJson('/api/v1/support/tickets', [
        'subject' => 'Payment not showing',
        'category' => 'payment',
        'body' => 'I paid yesterday but it is still pending.',
    ])->assertCreated();

    expect($res->json('data.reference'))->toMatch('/^SUP-\d{4}-[A-Z0-9]{5}$/');
    $res->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.status_label', 'Open')
        ->assertJsonPath('data.category', 'payment')
        ->assertJsonPath('data.order_reference', null)
        ->assertJsonPath('data.messages.0.is_own', true)
        ->assertJsonPath('data.messages.0.sender.is_staff', false)
        ->assertJsonPath('data.messages.0.sender.name', $user->name);

    Notification::assertSentTo([$staff, $moderator], SupportTicketOpenedNotification::class);
    Notification::assertNotSentTo($user, SupportTicketOpenedNotification::class);
});

it('validates category, subject length and body length', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->postJson('/api/v1/support/tickets', [
        'subject' => str_repeat('a', 161), 'category' => 'billing', 'body' => 'hey',
    ])->assertUnprocessable()->assertJsonValidationErrors(['subject', 'category', 'body'], 'error.details');
});

it('accepts an order_reference the user can see and rejects one they cannot', function () {
    [$order, $buyer] = supportOrder();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')->postJson('/api/v1/support/tickets', [
        'subject' => 'Order issue', 'category' => 'order', 'body' => 'Where is my order?',
        'order_reference' => $order->reference_code,
    ])->assertUnprocessable()->assertJsonValidationErrors(['order_reference'], 'error.details');

    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/support/tickets', [
        'subject' => 'Order issue', 'category' => 'order', 'body' => 'Where is my order?',
        'order_reference' => $order->reference_code,
    ])->assertCreated()->assertJsonPath('data.order_reference', $order->reference_code);
});

it('lists only the callers own tickets newest first', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $first = supportOpen($user);
    $this->travel(1)->minutes();
    $second = supportOpen($user, ['subject' => 'Second']);
    supportOpen($other);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/support/tickets')
        ->assertOk()
        ->assertJsonCount(1 + 1, 'data')
        ->assertJsonPath('data.0.reference', $second)
        ->assertJsonPath('data.1.reference', $first)
        ->assertJsonMissingPath('data.0.messages');
});

it('shows own ticket with messages and 404s on someone elses', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $ref = supportOpen($user);

    $this->actingAs($user, 'sanctum')->getJson("/api/v1/support/tickets/{$ref}")
        ->assertOk()->assertJsonCount(1, 'data.messages');

    $this->actingAs($other, 'sanctum')->getJson("/api/v1/support/tickets/{$ref}")->assertNotFound();
    $this->actingAs($other, 'sanctum')->postJson("/api/v1/support/tickets/{$ref}/reply", ['body' => 'hi'])->assertNotFound();
});

it('requires auth for user endpoints', function () {
    $this->getJson('/api/v1/support/tickets')->assertUnauthorized();
});

/* -------------------------------------------------------------- staff */

it('forbids non-staff from the staff inbox', function () {
    $user = User::factory()->create();
    $ref = supportOpen($user);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/staff/support/tickets')->assertForbidden();
    $this->actingAs($user, 'sanctum')->getJson("/api/v1/staff/support/tickets/{$ref}")->assertForbidden();
    $this->actingAs($user, 'sanctum')->postJson("/api/v1/staff/support/tickets/{$ref}/reply", ['body' => 'x'])->assertForbidden();
    $this->actingAs(supportStaff('billing_officer'), 'sanctum')->getJson('/api/v1/staff/support/tickets')->assertForbidden();
});

it('lets staff list with status filter and requester', function () {
    $user = User::factory()->create();
    $ref = supportOpen($user);
    $staff = supportStaff('moderator');

    $this->actingAs($staff, 'sanctum')->getJson('/api/v1/staff/support/tickets?status=open')
        ->assertOk()
        ->assertJsonPath('data.0.reference', $ref)
        ->assertJsonPath('data.0.requester.name', $user->name);

    $this->actingAs($staff, 'sanctum')->getJson('/api/v1/staff/support/tickets?status=closed')
        ->assertOk()->assertJsonCount(0, 'data');
});

it('runs the full reply and status lifecycle with notifications', function () {
    $user = User::factory()->create();
    $staff = supportStaff('admin');
    $ref = supportOpen($user);

    Notification::fake();

    // Staff reply -> pending by default, owner notified with screen=support.
    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/staff/support/tickets/{$ref}/reply", ['body' => 'Please retry now.'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.messages.1.is_own', true)
        ->assertJsonPath('data.messages.1.sender.is_staff', true);

    Notification::assertSentTo($user, SupportTicketStaffReplyNotification::class, function ($n) use ($user, $ref) {
        $data = $n->toArray($user);

        return $data['screen'] === 'support' && $data['reference'] === $ref;
    });

    // Owner sees staff message as not own.
    $this->actingAs($user, 'sanctum')->getJson("/api/v1/support/tickets/{$ref}")
        ->assertJsonPath('data.messages.1.is_own', false)
        ->assertJsonPath('data.messages.1.sender.is_staff', true);

    // User reply reopens a pending ticket and notifies staff.
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/support/tickets/{$ref}/reply", ['body' => 'Still broken.'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open');
    Notification::assertSentTo($staff, SupportTicketUserReplyNotification::class);

    // Staff resolves, then closes.
    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/staff/support/tickets/{$ref}/reply", ['body' => 'Fixed.', 'status' => 'resolved'])
        ->assertJsonPath('data.status', 'resolved');
    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/staff/support/tickets/{$ref}/reply", ['body' => 'Closing.', 'status' => 'closed'])
        ->assertJsonPath('data.status', 'closed');

    expect(SupportTicket::where('reference', $ref)->first()->closed_at)->not->toBeNull();

    // Closed: user can no longer reply.
    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/support/tickets/{$ref}/reply", ['body' => 'Hello?'])
        ->assertStatus(409);

    // Invalid staff status rejected.
    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/staff/support/tickets/{$ref}/reply", ['body' => 'x', 'status' => 'open'])
        ->assertUnprocessable();
});

/* ----------------------------------------------------------- filament */

it('shows the support ticket admin list to staff only', function () {
    supportOpen(User::factory()->create());

    $this->actingAs(supportStaff('moderator'), 'web')->get('/admin/support-tickets')->assertOk();
    $this->actingAs(supportStaff('billing_officer'), 'web')->get('/admin/support-tickets')->assertForbidden();
});
