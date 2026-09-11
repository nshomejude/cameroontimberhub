<?php

use App\Models\Company;
use App\Models\Quote;
use App\Models\QuoteItem;
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

/**
 * A real awarded order (RFQ → routed quote → accepted), attached to
 * $buyer — mirrors OrderApiTest's apiOrder() helper so this dashboard has
 * real quotes, orders and a supplier to summarise.
 */
function dashboardOrder(User $buyer): void
{
    $supplierCompany = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create(['user_id' => $buyer->getKey()]);
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
}

it('returns the buyer dashboard feed with no web urls', function () {
    $buyer = User::factory()->create();
    dashboardOrder($buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/dashboard')
        ->assertOk();

    $stats = $response->json('data.stats');
    expect($stats)->not->toBeEmpty();

    foreach ($stats as $stat) {
        expect($stat)->toHaveKeys(['key', 'label', 'value'])
            ->and($stat)->not->toHaveKey('url');
    }

    expect(count($response->json('data.recent_orders')))->toBeLessThanOrEqual(5)
        ->and(count($response->json('data.recent_quotes')))->toBeLessThanOrEqual(5)
        ->and(count($response->json('data.top_suppliers')))->toBeLessThanOrEqual(5);

    expect($response->json('data.recent_orders.0.status'))->not->toBeNull();
    expect($response->json('data.recent_quotes.0.status'))->not->toBeNull();

    foreach ($response->json('data.activity') as $entry) {
        expect($entry)->toHaveKeys(['type', 'reference'])
            ->and($entry)->not->toHaveKey('url');
    }
});

it('returns an empty-state dashboard without throwing', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.stats.0.value', 0)
        ->assertJsonCount(0, 'data.recent_orders')
        ->assertJsonCount(0, 'data.recent_quotes')
        ->assertJsonCount(0, 'data.top_suppliers')
        ->assertJsonCount(0, 'data.activity')
        ->assertJsonPath('data.orders_by_status', null)
        ->assertJsonPath('data.value_trend', null);
});

it('requires buyer auth for the dashboard', function () {
    $this->getJson('/api/v1/dashboard')->assertUnauthorized();
});

it('rejects a non-buyer (supplier) account the same way other buyer endpoints do', function () {
    $supplierUser = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($supplierUser);

    $ordersResponse = $this->actingAs($supplierUser, 'sanctum')->getJson('/api/v1/orders');
    $dashboardResponse = $this->actingAs($supplierUser, 'sanctum')->getJson('/api/v1/dashboard');

    $dashboardResponse->assertStatus($ordersResponse->getStatusCode());
});
