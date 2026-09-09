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

it('rejects resolving a dispute before it has reached review', function () {
    [$order, $buyer] = buildDisputeOrderContext();
    $admin = User::factory()->create();

    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Delay, 'Shipment is three weeks late.');

    expect(fn () => $dispute->resolve($admin, 'Too early to resolve.'))
        ->toThrow(RuntimeException::class);

    $dispute->refresh();
    expect($dispute->status)->toBe(DisputeStatus::Opened);
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
