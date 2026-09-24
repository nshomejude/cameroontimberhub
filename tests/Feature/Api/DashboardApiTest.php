<?php

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CompanyDocument;
use App\Models\CompanyGallery;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use App\Models\User;
use App\Models\VerificationRequest;
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

    expect(array_key_first($response->json('data')))->toBe('role')
        ->and($response->json('data.role'))->toBe('buyer');

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

/**
 * Product-scope change (RBAC foundation): the dashboard route moved from
 * `api.buyer`-only to "any authenticated user". A supplier still 403s on
 * buyer-only routes like /orders (unchanged). The dashboard now returns a
 * REAL supplier payload (SupplierDashboard), not an empty stub — a 403
 * there would be indistinguishable from a permissions bug to the mobile
 * client, and an empty stub would hide real data from a supplier who has
 * it. This replaces the earlier "empty-but-valid" expectation now that
 * the supplier branch is implemented.
 */
it('gives a supplier an honest empty-but-valid dashboard when they have no activity', function () {
    $supplierUser = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($supplierUser);

    $this->actingAs($supplierUser, 'sanctum')->getJson('/api/v1/orders')->assertForbidden();

    $this->actingAs($supplierUser, 'sanctum')
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.role', 'supplier')
        ->assertJsonCount(0, 'data.recent_orders')
        ->assertJsonCount(0, 'data.recent_quotes')
        ->assertJsonCount(0, 'data.activity');

    // The stats tiles themselves are still real (all zero), not an empty array.
    expect($this->actingAs($supplierUser, 'sanctum')->getJson('/api/v1/dashboard')->json('data.stats'))
        ->not->toBeEmpty();
});

it('returns real, non-empty supplier dashboard data when the company has RFQs/orders/products', function () {
    $supplierUser = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();
    $company->users()->attach($supplierUser, ['role' => 'owner', 'is_primary' => true]);

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

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'description' => 'Sawn timber',
        'quantity' => 50,
        'unit_price' => 200.00,
        'line_total' => Quote::lineTotal(50, 200.00),
    ]);
    $quote->load('items')->recalculateTotals()->save();

    app(App\Services\QuoteService::class)->accept($quote);

    \App\Models\Product::factory()->create([
        'company_id' => $company->getKey(),
        'status' => \App\Enums\ProductStatus::Active,
    ]);

    $response = $this->actingAs($supplierUser, 'sanctum')
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.role', 'supplier');

    expect($response->json('data.recent_orders'))->not->toBeEmpty()
        ->and($response->json('data.recent_quotes'))->not->toBeEmpty()
        ->and($response->json('data.products_by_status'))->not->toBeEmpty()
        ->and($response->json('data.activity'))->not->toBeEmpty();

    $stats = collect($response->json('data.stats'))->keyBy('key');
    expect($stats->get('active_orders')['value'])->toBeGreaterThan(0);
});

it('gives staff an empty-but-valid dashboard instead of a 403', function () {
    $staffUser = User::factory()->create();
    $staffUser->assignRole('admin');

    $this->actingAs($staffUser, 'sanctum')
        ->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.role', 'staff')
        ->assertJsonCount(0, 'data.recent_orders');
});

/**
 * profile_completion used to be a dead stored column (never written outside
 * tests), so a fully verified demo company would show 0% in the mobile app
 * — self-contradictory for a "verified" supplier. It is now computed live
 * from the same fields OnboardingChecklist checks, so a fully-profiled
 * company reports a real, near-100% figure with nothing missing.
 */
it('reports a real, near-complete profile_completeness for a fully-profiled supplier', function () {
    $supplierUser = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();
    $company->users()->attach($supplierUser, ['role' => 'owner', 'is_primary' => true]);

    CompanyContact::factory()->create(['company_id' => $company->id]);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'gallery/test.jpg']);
    CompanyDocument::factory()->create(['company_id' => $company->id]);
    VerificationRequest::factory()->create(['company_id' => $company->id]);
    $company->species()->attach(Species::factory()->create());

    $response = $this->actingAs($supplierUser, 'sanctum')
        ->getJson('/api/v1/dashboard')
        ->assertOk();

    expect($response->json('data.profile_completeness.percent'))->toBeGreaterThanOrEqual(90)
        ->and($response->json('data.profile_completeness.missing'))->toBeEmpty();

    $stats = collect($response->json('data.stats'))->keyBy('key');
    expect($stats->get('profile_completion')['value'])->toBeGreaterThanOrEqual(90);
});

it('reports a low profile_completeness with named gaps for a bare supplier company', function () {
    $supplierUser = User::factory()->create();
    $company = Company::factory()->create([
        'description' => 'short',
        'region' => null,
        'city' => null,
        'website_url' => null,
        'email' => null,
        'phone' => null,
    ]);
    $company->users()->attach($supplierUser, ['role' => 'owner', 'is_primary' => true]);

    $response = $this->actingAs($supplierUser, 'sanctum')
        ->getJson('/api/v1/dashboard')
        ->assertOk();

    expect($response->json('data.profile_completeness.percent'))->toBeLessThan(60)
        ->and($response->json('data.profile_completeness.missing'))->not->toBeEmpty()
        ->and($response->json('data.profile_completeness.missing'))->toContain('Basic profile completed');
});
