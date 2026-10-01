<?php

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Models\Company;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\DisputeService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/**
 * Builds a real Order the way the platform does: an approved RFQ routed to a
 * supplier company, a submitted quote, and a buyer user who awards it. This
 * mirrors OrderComplianceWiringTest's approach so order.company_id (supplier)
 * and order.user_id (buyer) are both genuine.
 *
 * @return array{0: Order, 1: User, 2: Company}
 */
function buildDisputeOrderContext(): array
{
    $supplierCompany = Company::factory()->publiclyVisible()->create();
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

    return [$order, $buyer, $supplierCompany];
}

function attachDisputeSupplierUser(Company $company): User
{
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

/* --------------------------------------------------------------------- */

it('carries a dispute through its full lifecycle to closed', function () {
    [$order, $buyer, $supplierCompany] = buildDisputeOrderContext();
    $supplierUser = attachDisputeSupplierUser($supplierCompany);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $service = app(DisputeService::class);

    // Opened, by the buyer.
    $dispute = $service->open($order, $buyer, DisputeCategory::Quality, 'The delivered timber does not match spec.');
    expect($dispute->status)->toBe(DisputeStatus::Opened)
        ->and($dispute->raised_by_user_id)->toBe($buyer->id)
        ->and($dispute->respondent_company_id)->toBe($supplierCompany->id);

    // Evidence submission -> awaiting counterparty response.
    $service->submitEvidence($dispute, $buyer, 'Photos of the mismatched grade.');
    $dispute->refresh();
    expect($dispute->status)->toBe(DisputeStatus::CounterpartyResponsePending);

    // Counterparty (supplier) responds -> under review.
    $service->reply($dispute, $supplierUser, 'We dispute this — the grade matches the agreed spec.');
    $dispute->refresh();
    expect($dispute->status)->toBe(DisputeStatus::UnderReview);

    // Admin decision -> resolved.
    $dispute->resolve($admin, 'Partial credit issued to the buyer.');
    $dispute->refresh();
    expect($dispute->status)->toBe(DisputeStatus::Resolved)
        ->and($dispute->resolution_notes)->toBe('Partial credit issued to the buyer.')
        ->and($dispute->resolved_by)->toBe($admin->id);

    // Appeal, by either party.
    $dispute->appeal($buyer);
    $dispute->refresh();
    expect($dispute->status)->toBe(DisputeStatus::Appealed);

    // Admin closes.
    $dispute->close($admin);
    $dispute->refresh();
    expect($dispute->status)->toBe(DisputeStatus::Closed)
        ->and($dispute->closed_at)->not->toBeNull();
});

it('lets an admin resolve a stalled dispute straight from opened, but not a closed one', function () {
    [$order, $buyer] = buildDisputeOrderContext();
    $admin = User::factory()->create();

    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Delay, 'Shipment is three weeks late.');

    $dispute->resolve($admin, 'Supplier never responded; refund ordered.');
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Resolved);

    $dispute->close($admin);
    expect(fn () => $dispute->resolve($admin, 'Again.'))->toThrow(RuntimeException::class);
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Closed);
});

it('requires an appealed dispute to be moved back to review before re-deciding', function () {
    [$order, $buyer] = buildDisputeOrderContext();
    $admin = User::factory()->create();

    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Delay, 'Late.');
    $dispute->moveToReview($admin);
    expect($dispute->refresh()->status)->toBe(DisputeStatus::UnderReview);
    $dispute->resolve($admin, 'First decision.');
    $dispute->appeal($buyer);

    expect(fn () => $dispute->resolve($admin, 'Skip review.'))->toThrow(RuntimeException::class);

    $dispute->moveToReview($admin);
    $dispute->resolve($admin, 'Appeal decision.');
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Resolved)
        ->and($dispute->resolution_notes)->toBe('Appeal decision.');

    expect(fn () => $dispute->moveToReview($admin))->toThrow(RuntimeException::class);
});

it('rejects closing a dispute that has not been resolved', function () {
    [$order, $buyer] = buildDisputeOrderContext();
    $admin = User::factory()->create();

    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Other, 'Miscellaneous issue.');

    expect(fn () => $dispute->close($admin))->toThrow(RuntimeException::class);
});

it('rejects a non-party company from opening a dispute on an order', function () {
    [$order] = buildDisputeOrderContext();
    $stranger = User::factory()->create();

    expect(fn () => app(DisputeService::class)->open($order, $stranger, DisputeCategory::Quality, 'Not my order.'))
        ->toThrow(RuntimeException::class);

    expect(Dispute::where('order_id', $order->id)->exists())->toBeFalse();
});

it('rejects a non-party company from responding to a dispute', function () {
    [$order, $buyer] = buildDisputeOrderContext();
    $stranger = User::factory()->create();

    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Payment, 'Payment discrepancy.');

    expect(fn () => app(DisputeService::class)->reply($dispute, $stranger, 'Butting in.'))
        ->toThrow(RuntimeException::class);

    expect(fn () => $dispute->submitEvidence($stranger))->toThrow(RuntimeException::class);
    expect(fn () => $dispute->appeal($stranger))->toThrow(RuntimeException::class);
});

it('rejects a non-party company from opening a dispute via the HTTP endpoint', function () {
    [$order] = buildDisputeOrderContext();
    $stranger = User::factory()->create();

    $response = $this->actingAs($stranger)->post(route('disputes.store', ['order' => $order->id]), [
        'category' => 'quality',
        'description' => 'Trying to open a dispute I have no business opening.',
    ]);

    $response->assertForbidden();
    expect(Dispute::where('order_id', $order->id)->exists())->toBeFalse();
});

it('lets the buyer open a dispute via the HTTP endpoint', function () {
    [$order, $buyer] = buildDisputeOrderContext();

    $response = $this->actingAs($buyer)->post(route('disputes.store', ['order' => $order->id]), [
        'category' => 'quantity',
        'description' => 'Short shipment — 20 fewer m3 than invoiced.',
    ]);

    $response->assertRedirect();
    expect(Dispute::where('order_id', $order->id)->where('raised_by_user_id', $buyer->id)->exists())->toBeTrue();
});

/* ------------------------------------------- notifications & admin desk */

it('notifies the supplier members and the dispute desk when a dispute is opened on the web', function () {
    \Illuminate\Support\Facades\Notification::fake();
    [$order, $buyer, $supplierCompany] = buildDisputeOrderContext();
    $supplierUser = attachDisputeSupplierUser($supplierCompany);
    $desk = User::factory()->create();
    $desk->assignRole('admin');
    $outsider = User::factory()->create();

    $this->actingAs($buyer)->post(route('disputes.store', $order), [
        'category' => DisputeCategory::Quality->value, 'description' => 'Wrong grade delivered.',
    ])->assertRedirect();

    \Illuminate\Support\Facades\Notification::assertSentTo($supplierUser, \App\Notifications\DisputeOpenedNotification::class);
    \Illuminate\Support\Facades\Notification::assertNotSentTo($buyer, \App\Notifications\DisputeOpenedNotification::class);
    \Illuminate\Support\Facades\Notification::assertSentTo($desk, \App\Notifications\DisputeOpenedStaffNotification::class,
        fn ($n, $channels) => in_array('mail', $channels, true) && in_array('database', $channels, true));
    \Illuminate\Support\Facades\Notification::assertNotSentTo($outsider, \App\Notifications\DisputeOpenedStaffNotification::class);
});

it('notifies the other party when a web reply is posted', function () {
    \Illuminate\Support\Facades\Notification::fake();
    [$order, $buyer, $supplierCompany] = buildDisputeOrderContext();
    $supplierUser = attachDisputeSupplierUser($supplierCompany);
    $service = app(DisputeService::class);
    $dispute = $service->open($order, $buyer, DisputeCategory::Quality, 'Wrong grade.');
    $service->submitEvidence($dispute, $buyer, 'Photos.');

    $this->actingAs($supplierUser)->post(route('disputes.reply', [$order, $dispute]), ['body' => 'We disagree.'])->assertRedirect();

    \Illuminate\Support\Facades\Notification::assertSentTo($buyer, \App\Notifications\DisputeReplyNotification::class);
    \Illuminate\Support\Facades\Notification::assertNotSentTo($supplierUser, \App\Notifications\DisputeReplyNotification::class);
});

it('lets the admin desk move, resolve and close disputes and notifies both parties', function () {
    \Illuminate\Support\Facades\Notification::fake();
    [$order, $buyer, $supplierCompany] = buildDisputeOrderContext();
    $supplierUser = attachDisputeSupplierUser($supplierCompany);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Delay, 'Late.');
    $this->actingAs($admin);

    \Livewire\Livewire::test(\App\Filament\Resources\Disputes\Pages\ListDisputes::class)
        ->assertTableActionVisible('moveToReview', $dispute)
        ->assertTableActionVisible('resolve', $dispute)
        ->callTableAction('moveToReview', $dispute);
    expect($dispute->refresh()->status)->toBe(DisputeStatus::UnderReview);

    \Livewire\Livewire::test(\App\Filament\Resources\Disputes\Pages\ListDisputes::class)
        ->callTableAction('resolve', $dispute, ['resolution_notes' => 'Partial refund.']);
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Resolved);

    foreach ([$buyer, $supplierUser] as $party) {
        \Illuminate\Support\Facades\Notification::assertSentTo($party, \App\Notifications\DisputeResolvedNotification::class,
            fn ($n, $channels) => $n->event === 'resolved' && in_array('mail', $channels, true)
                && $n->toArray($party)['dispute_id'] === $dispute->id
                && $n->toArray($party)['reference'] === $order->reference_code);
    }

    $dispute->appeal($buyer);
    \Livewire\Livewire::test(\App\Filament\Resources\Disputes\Pages\ListDisputes::class)
        ->assertTableActionHidden('resolve', $dispute)
        ->callTableAction('moveToReview', $dispute)
        ->callTableAction('resolve', $dispute, ['resolution_notes' => 'Upheld.']);
    \Illuminate\Support\Facades\Notification::assertSentTo($supplierUser, \App\Notifications\DisputeResolvedNotification::class, fn ($n) => $n->event === 'appeal_decided');

    \Livewire\Livewire::test(\App\Filament\Resources\Disputes\Pages\ListDisputes::class)->callTableAction('close', $dispute);
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Closed);
    \Illuminate\Support\Facades\Notification::assertSentTo($buyer, \App\Notifications\DisputeResolvedNotification::class, fn ($n) => $n->event === 'closed');

    // Mail bodies render with a deep link to the dispute.
    $html = (string) (new \App\Notifications\DisputeResolvedNotification($dispute, 'appeal_decided'))->toMail($buyer)->render();
    expect($html)->toContain($order->reference_code)->toContain(route('disputes.show', [$order->id, $dispute->id]));
    expect((string) (new \App\Notifications\DisputeOpenedStaffNotification($dispute))->toMail($admin)->render())->toContain($order->reference_code);
});

it('alerts the dispute desk when a party appeals on the web or over the API', function (string $via) {
    \Illuminate\Support\Facades\Notification::fake();
    [$order, $buyer] = buildDisputeOrderContext();
    $desk = User::factory()->create();
    $desk->assignRole('admin');
    $outsider = User::factory()->create();
    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Quality, 'Wrong grade.');
    $dispute->update(['status' => DisputeStatus::Resolved, 'resolved_at' => now()]);

    if ($via === 'web') {
        $this->actingAs($buyer)->post(route('disputes.appeal', [$order, $dispute]))->assertRedirect();
    } else {
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->reference_code}/disputes/{$dispute->id}/appeal")
            ->assertOk();
    }

    expect($dispute->refresh()->status)->toBe(DisputeStatus::Appealed);
    \Illuminate\Support\Facades\Notification::assertSentTo($desk, \App\Notifications\DisputeAppealedStaffNotification::class,
        fn ($n, $channels) => in_array('mail', $channels, true) && in_array('database', $channels, true)
            && $n->toArray($desk)['dispute_id'] === $dispute->id);
    \Illuminate\Support\Facades\Notification::assertNotSentTo($outsider, \App\Notifications\DisputeAppealedStaffNotification::class);
    \Illuminate\Support\Facades\Notification::assertNotSentTo($buyer, \App\Notifications\DisputeAppealedStaffNotification::class);

    expect((string) (new \App\Notifications\DisputeAppealedStaffNotification($dispute))->toMail($desk)->render())
        ->toContain($order->reference_code);
})->with(['web', 'api']);

it('does not alert the dispute desk when an appeal is refused', function () {
    \Illuminate\Support\Facades\Notification::fake();
    [$order, $buyer] = buildDisputeOrderContext();
    $desk = User::factory()->create();
    $desk->assignRole('admin');
    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Quality, 'Wrong grade.');

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->reference_code}/disputes/{$dispute->id}/appeal")
        ->assertStatus(409);

    \Illuminate\Support\Facades\Notification::assertNothingSentTo($desk);
});
