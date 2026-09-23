<?php

use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

function supplierOrderApiUser(): array
{
    $user = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();
    $company->users()->attach($user);

    return [$user, $company];
}

/** A real awarded order for the given supplier company, via the actual accept path. */
function awardedOrderFor(Company $company, float $unitPrice = 185.00): Order
{
    $rfq = Rfq::factory()->approved()->create();
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

    $quote->items()->create([
        'description' => 'Sapele sawn timber', 'quantity' => 50, 'unit' => 'm3',
        'unit_price' => $unitPrice, 'line_total' => Quote::lineTotal(50, $unitPrice),
    ]);
    $quote->load('items')->recalculateTotals()->save();

    $accepted = app(QuoteService::class)->accept($quote->fresh());

    return Order::where('quote_id', $accepted->getKey())->firstOrFail();
}

/* --------------------------------------------------------------- listing */

it('lists only orders where the caller company is the supplier', function () {
    [$user, $company] = supplierOrderApiUser();
    $other = Company::factory()->publiclyVisible()->create();

    $mine = awardedOrderFor($company);
    awardedOrderFor($other);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/orders')
        ->assertOk();

    $references = collect($response->json('data'))->pluck('reference');

    expect($references)->toContain($mine->reference_code)
        ->and($references)->toHaveCount(1);
});

it('filters the caller orders by status', function () {
    [$user, $company] = supplierOrderApiUser();
    $order = awardedOrderFor($company);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/orders?status=awarded')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/orders?status=completed')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/* ----------------------------------------------------------------- show */

it('shows the buyer identity on a supplier own order', function () {
    [$user, $company] = supplierOrderApiUser();
    $order = awardedOrderFor($company);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/orders/'.$order->reference_code)
        ->assertOk()
        ->assertJsonPath('data.reference', $order->reference_code)
        ->assertJsonPath('data.buyer_email', $order->buyer_email)
        ->assertJsonPath('data.buyer_name', $order->buyer_name);
});

it('404s an order belonging to a buyer, where the caller company is not the supplier', function () {
    [$user] = supplierOrderApiUser();
    $otherSupplier = Company::factory()->publiclyVisible()->create();
    $order = awardedOrderFor($otherSupplier);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/orders/'.$order->reference_code)
        ->assertNotFound();
});

it('404s another suppliers order', function () {
    [$user] = supplierOrderApiUser();
    $another = Company::factory()->publiclyVisible()->create();
    $theirs = awardedOrderFor($another);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/orders/'.$theirs->reference_code)
        ->assertNotFound();
});

/* --------------------------------------------------------------- gating */

it('403s a buyer with no company hitting supplier orders', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/supplier/orders')
        ->assertForbidden();
});

it('401s a guest on supplier orders', function () {
    $this->getJson('/api/v1/supplier/orders')->assertUnauthorized();
});

it('401s a guest on a single supplier order', function () {
    [$user, $company] = supplierOrderApiUser();
    $order = awardedOrderFor($company);

    $this->getJson('/api/v1/supplier/orders/'.$order->reference_code)->assertUnauthorized();
});
