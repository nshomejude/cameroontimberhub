<?php

use App\Enums\CompanyUserRole;
use App\Enums\OrganisationType;
use App\Enums\QuoteStatus;
use App\Enums\RfqStatus;
use App\Filament\Exporter\Pages\BuyerProcurementWorkspace;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\TradeAssuranceAgreement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A buyer account that also belongs to a Buyer-type company (required to
 * pass the exporter panel's EnsureExporterOnboarded middleware, which logs
 * out any user with no company membership at all).
 */
function workspaceBuyer(array $attributes = []): User
{
    $user = User::factory()->create($attributes + ['email' => 'buyer'.uniqid().'@example.com']);

    $company = Company::factory()->publiclyVisible()->create(['type' => OrganisationType::Buyer]);
    $company->users()->attach($user, ['role' => CompanyUserRole::Owner->value, 'is_primary' => true]);

    return $user;
}

function workspaceRfq(User $buyer, array $attributes = []): Rfq
{
    return Rfq::factory()->approved()->create(array_merge([
        'user_id' => $buyer->getKey(),
        'buyer_email' => $buyer->email,
    ], $attributes));
}

function workspaceQuote(Rfq $rfq, array $overrides = []): Quote
{
    $supplier = Company::factory()->publiclyVisible()->create();

    $routing = RfqCompany::firstOrCreate(
        ['rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey()],
        ['status' => 'sent', 'routed_at' => now()],
    );

    $quote = Quote::factory()->submitted()->create(array_merge([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $supplier->getKey(),
        'rfq_company_id' => $routing->getKey(),
    ], $overrides));

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'quantity' => 10,
        'unit_price' => 100,
        'line_total' => Quote::lineTotal(10, 100),
    ]);

    return $quote->load('items')->recalculateTotals()->save() ? $quote->fresh() : $quote;
}

function workspaceOrder(Rfq $rfq, array $overrides = []): Order
{
    $quote = workspaceQuote($rfq, ['status' => QuoteStatus::Accepted]);
    $supplier = $quote->company;

    return Order::create(array_merge([
        'quote_id' => $quote->getKey(),
        'rfq_id' => $rfq->getKey(),
        'company_id' => $supplier->getKey(),
        'user_id' => $rfq->user_id,
        'reference_code' => 'ORD-'.uniqid(),
        'status' => 'awarded',
        'buyer_name' => 'Test buyer',
        'buyer_email' => $rfq->buyer_email,
        'supplier_name' => $supplier->name,
        'currency' => 'USD',
        'subtotal_amount' => 1000,
        'total_amount' => 1000,
        'payment_status' => 'unpaid',
        'amount_paid' => 0,
        'awarded_at' => now(),
    ], $overrides));
}

/* --------------------------------------------------------------- access */

it('lets a buyer with a company reach their procurement workspace', function () {
    $buyer = workspaceBuyer();

    $this->actingAs($buyer)->get(BuyerProcurementWorkspace::getUrl(panel: 'exporter'))->assertOk();
});

/* -------------------------------------------------------------- content */

it('shows the buyers own open rfqs, pending quotes, active orders and milestones', function () {
    $buyer = workspaceBuyer();
    $rfq = workspaceRfq($buyer, ['reference_code' => 'RFQ-MINE-0001']);
    workspaceQuote($rfq, ['reference_code' => 'QTE-MINE-0001', 'status' => QuoteStatus::Submitted]);

    $orderRfq = workspaceRfq($buyer, ['reference_code' => 'RFQ-MINE-0002']);
    $order = workspaceOrder($orderRfq, ['reference_code' => 'ORD-MINE-0001']);
    $agreement = TradeAssuranceAgreement::createDefaultMilestones($order, $buyer);

    $response = $this->actingAs($buyer)->get(BuyerProcurementWorkspace::getUrl(panel: 'exporter'));

    $response->assertOk()
        ->assertSee('RFQ-MINE-0001')
        ->assertSee('QTE-MINE-0001')
        ->assertSee('ORD-MINE-0001')
        ->assertSee('Order Confirmed');

    expect($agreement->milestones()->count())->toBe(4);
});

it('shows a graceful empty state when the buyer has no activity', function () {
    $buyer = workspaceBuyer();

    $this->actingAs($buyer)->get(BuyerProcurementWorkspace::getUrl(panel: 'exporter'))
        ->assertOk()
        ->assertSee('You have no open RFQs right now.')
        ->assertSee('No quotes are waiting on you right now.')
        ->assertSee('You have no active orders right now.')
        ->assertSee('No trade-assurance milestones need your confirmation right now.');
});

/* ---------------------------------------------------------------- scoping */

it('never shows one buyers procurement data to a different buyer', function () {
    $mine = workspaceBuyer();
    $theirs = workspaceBuyer();

    $myRfq = workspaceRfq($mine, ['reference_code' => 'RFQ-MINE-9001']);
    $theirRfq = workspaceRfq($theirs, ['reference_code' => 'RFQ-THEIRS-9001']);

    workspaceQuote($myRfq, ['reference_code' => 'QTE-MINE-9001', 'status' => QuoteStatus::Submitted]);
    workspaceQuote($theirRfq, ['reference_code' => 'QTE-THEIRS-9001', 'status' => QuoteStatus::Submitted]);

    $myOrder = workspaceOrder($myRfq, ['reference_code' => 'ORD-MINE-9001']);
    $theirOrder = workspaceOrder($theirRfq, ['reference_code' => 'ORD-THEIRS-9001']);

    $response = $this->actingAs($mine)->get(BuyerProcurementWorkspace::getUrl(panel: 'exporter'));

    $response->assertOk()
        ->assertSee('RFQ-MINE-9001')->assertDontSee('RFQ-THEIRS-9001')
        ->assertSee('QTE-MINE-9001')->assertDontSee('QTE-THEIRS-9001')
        ->assertSee('ORD-MINE-9001')->assertDontSee('ORD-THEIRS-9001');
});
