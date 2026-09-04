<?php

use App\Enums\TradeAssuranceMilestoneStatus;
use App\Filament\Exporter\Resources\TradeAssuranceMilestones\TradeAssuranceMilestoneResource;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\TradeAssuranceAgreement;
use App\Models\User;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/**
 * Builds a real Order via the award path (mirrors OrderTest's helpers, given
 * a distinct name since Pest loads every file into one process) and attaches
 * a buyer account by forcing `user_id` — the factories leave RFQs guest by
 * default, and Order has no buyer-company FK to hook into instead.
 */
function taOrder(?User $buyer = null): array
{
    $supplierCompany = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create();
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

    app(QuoteService::class)->accept($quote);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    if ($buyer) {
        $order->forceFill(['user_id' => $buyer->getKey()])->save();
    }

    return [$order, $supplierCompany];
}

/* ------------------------------------------------------- agreement + defaults */

it('creates an agreement with the default milestone set', function () {
    [$order] = taOrder();

    $agreement = TradeAssuranceAgreement::createDefaultMilestones($order);

    expect($agreement->order_id)->toBe($order->getKey())
        ->and($agreement->milestones)->toHaveCount(4);

    $titles = $agreement->milestones->pluck('title')->all();
    expect($titles)->toBe(['Order Confirmed', 'Goods Dispatched', 'Goods Delivered', 'Buyer Confirmation']);

    foreach ($agreement->milestones as $milestone) {
        expect($milestone->status)->toBe(TradeAssuranceMilestoneStatus::Pending);
    }
});

/* ------------------------------------------------------------- buyer confirm */

it('lets the order buyer confirm a milestone', function () {
    $buyer = User::factory()->create();
    [$order] = taOrder($buyer);

    $agreement = TradeAssuranceAgreement::createDefaultMilestones($order);
    $milestone = $agreement->milestones->first();

    $milestone->confirmByBuyer($buyer);
    $milestone->refresh();

    expect($milestone->status)->toBe(TradeAssuranceMilestoneStatus::BuyerConfirmed)
        ->and($milestone->confirmed_by)->toBe($buyer->getKey())
        ->and($milestone->confirmed_at)->not->toBeNull();
});

it('rejects confirmation from a user who is not the order buyer', function () {
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();
    [$order] = taOrder($buyer);

    $agreement = TradeAssuranceAgreement::createDefaultMilestones($order);
    $milestone = $agreement->milestones->first();

    expect(fn () => $milestone->confirmByBuyer($stranger))->toThrow(RuntimeException::class);

    $milestone->refresh();
    expect($milestone->status)->toBe(TradeAssuranceMilestoneStatus::Pending)
        ->and($milestone->confirmed_by)->toBeNull();
});

it('rejects confirmation when the order has no buyer account at all (guest order)', function () {
    [$order] = taOrder(); // no buyer -> user_id stays null
    $stranger = User::factory()->create();

    $agreement = TradeAssuranceAgreement::createDefaultMilestones($order);
    $milestone = $agreement->milestones->first();

    expect(fn () => $milestone->confirmByBuyer($stranger))->toThrow(RuntimeException::class);
});

/* --------------------------------------------------------- HTTP confirm route */

it('lets the buyer confirm a milestone via the account route', function () {
    $buyer = User::factory()->create();
    [$order] = taOrder($buyer);

    $agreement = TradeAssuranceAgreement::createDefaultMilestones($order);
    $milestone = $agreement->milestones->first();

    $this->actingAs($buyer)
        ->post(route('account.orders.trade-assurance.confirm', ['order' => $order, 'milestone' => $milestone]))
        ->assertRedirect();

    expect($milestone->refresh()->status)->toBe(TradeAssuranceMilestoneStatus::BuyerConfirmed);
});

it('404s a stranger who tries to confirm a milestone via the account route', function () {
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();
    [$order] = taOrder($buyer);

    $agreement = TradeAssuranceAgreement::createDefaultMilestones($order);
    $milestone = $agreement->milestones->first();

    $this->actingAs($stranger)
        ->post(route('account.orders.trade-assurance.confirm', ['order' => $order, 'milestone' => $milestone]))
        ->assertNotFound();

    expect($milestone->refresh()->status)->toBe(TradeAssuranceMilestoneStatus::Pending);
});

/* -------------------------------------------------------- Filament scoping */

it('scopes the exporter Trade Assurance resource to the signed-in company\'s own orders', function () {
    [$orderA, $companyA] = taOrder();
    [$orderB, $companyB] = taOrder();

    $agreementA = TradeAssuranceAgreement::createDefaultMilestones($orderA);
    $agreementB = TradeAssuranceAgreement::createDefaultMilestones($orderB);

    $userA = User::factory()->create();
    $companyA->users()->attach($userA->getKey(), ['role' => 'owner']);

    $this->actingAs($userA);

    $query = TradeAssuranceMilestoneResource::getEloquentQuery();
    $ids = $query->pluck('id')->all();

    $visibleIds = $agreementA->milestones()->pluck('id')->all();
    $hiddenIds = $agreementB->milestones()->pluck('id')->all();

    expect($ids)->toEqual($visibleIds);
    foreach ($hiddenIds as $hiddenId) {
        expect($ids)->not->toContain($hiddenId);
    }
});

it('shows nothing to a user with no company', function () {
    $user = User::factory()->create();
    [$order] = taOrder();
    TradeAssuranceAgreement::createDefaultMilestones($order);

    $this->actingAs($user);

    expect(TradeAssuranceMilestoneResource::getEloquentQuery()->count())->toBe(0);
});
