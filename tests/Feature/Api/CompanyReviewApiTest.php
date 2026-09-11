<?php

use App\Enums\CompanyReviewStatus;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\CompanyReview;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use App\Services\OrderLifecycleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/**
 * A completed order, its conversation, and both parties, built through the
 * real award -> lifecycle path exactly like `ReorderApiTest::reapiScene()`,
 * because eligibility is derived from that history and a faked one would
 * test nothing. Prefixed `crapi` so these helpers never collide with the
 * other feature files' scene builders — Pest loads every feature file into
 * one process.
 *
 * @return array{0: \App\Models\Conversation, 1: User, 2: Company, 3: User, 4: Order}
 */
function crapiScene(bool $completed = true): array
{
    $plan = Plan::factory()->create(['features' => ['leads_receive' => true]]);
    $company = Company::factory()->publiclyVisible()->create(['plan_id' => $plan->id]);
    $staff = User::factory()->create(['email' => 'crapistaff'.uniqid().'@example.com']);
    $company->users()->attach($staff, ['role' => \App\Enums\CompanyUserRole::Owner->value, 'is_primary' => true]);

    $buyer = User::factory()->create([
        'email' => 'crapibuyer'.uniqid().'@example.com',
        'email_verified_at' => now(),
    ]);

    $rfq = Rfq::factory()->approved()->create([
        'user_id' => $buyer->getKey(),
        'buyer_email' => $buyer->email,
    ]);
    $rfqItem = $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3',
    ]);

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
        'currency' => 'USD',
        'incoterm' => 'CIF',
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'rfq_item_id' => $rfqItem->getKey(),
        'description' => 'Premium Sapele Lumber (KD)',
        'quantity' => 50,
        'unit' => 'm3',
        'unit_price' => 620.00,
        'line_total' => Quote::lineTotal(50, 620.00),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    $conversation = app(MessagingService::class)->start($buyer, $company);
    $commerce = app(ChatCommerceService::class);
    $commerce->issueQuotation($conversation, $quote->refresh(), $staff);
    $commerce->acceptQuotation($conversation, $quote->refresh(), $buyer);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    if ($completed) {
        $lifecycle = app(OrderLifecycleService::class);
        $lifecycle->confirm($conversation, $order->refresh(), $staff);
        $lifecycle->startProduction($conversation, $order->refresh(), $staff);
        $lifecycle->ship($conversation, $order->refresh(), $staff);
        $lifecycle->deliver($conversation, $order->refresh(), $staff);
        $lifecycle->complete($conversation, $order->refresh(), $buyer);
    }

    return [$conversation->refresh(), $buyer, $company, $staff, $order->refresh()];
}

/* ============================================================ ELIGIBILITY */

it('reports eligible with no existing review for a completed order', function () {
    [, $buyer, , , $order] = crapiScene();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/review')
        ->assertOk()
        ->assertJsonPath('data.eligible', true)
        ->assertJsonPath('data.reason', null)
        ->assertJsonPath('data.existing_review', null);
});

it('reports ineligible with the real refusal reason for an order not yet completed', function () {
    [, $buyer, , , $order] = crapiScene(completed: false);

    expect($order->status)->toBe(OrderStatus::Awarded);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/review')
        ->assertOk()
        ->assertJsonPath('data.eligible', false)
        ->assertJsonPath('data.existing_review', null);

    expect($response->json('data.reason'))
        ->toBe('You can review a supplier once the order is completed.');
});

it('reports ineligible with the existing review when the buyer already reviewed this order', function () {
    [$c, $buyer, , , $order] = crapiScene();

    app(\App\Services\CompanyReviewService::class)->create($order, $buyer, ['rating' => 4, 'body' => 'Solid.'], $c);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/review')
        ->assertOk()
        ->assertJsonPath('data.eligible', false);

    expect($response->json('data.reason'))->toBe('You have already reviewed this order.')
        ->and($response->json('data.existing_review.rating'))->toBe(4)
        ->and($response->json('data.existing_review.body'))->toBe('Solid.')
        ->and($response->json('data.existing_review.status'))->toBe('published');
});

it("404s another buyer's order on the eligibility check", function () {
    [, , , , $order] = crapiScene();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/review')
        ->assertNotFound();
});

it('requires buyer auth for the eligibility check', function () {
    [, , , , $order] = crapiScene();

    $this->getJson('/api/v1/orders/'.$order->reference_code.'/review')->assertUnauthorized();
});

it('rejects a supplier account on the eligibility check', function () {
    [, , , , $order] = crapiScene();

    $supplierUser = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($supplierUser);

    $this->actingAs($supplierUser, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/review')
        ->assertForbidden();
});

/* ================================================================= STORE */

it('submits a valid review on an eligible, not-yet-reviewed order and updates the aggregate rating', function () {
    [, $buyer, $company, , $order] = crapiScene();

    expect((int) $company->rating_count)->toBe(0)
        ->and($company->rating_avg)->toBeNull();

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/review', [
            'rating' => 5,
            'body' => 'Excellent supplier, on time and on spec.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.body', 'Excellent supplier, on time and on spec.')
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.status_label', CompanyReviewStatus::Published->label());

    expect($response->json('data.id'))->not->toBeNull();

    $review = CompanyReview::where('order_id', $order->getKey())->firstOrFail();
    expect($review->rating)->toBe(5)
        ->and($review->status)->toBe(CompanyReviewStatus::Published)
        ->and($review->user_id)->toBe($buyer->getKey());

    $company->refresh();
    expect($company->rating_count)->toBe(1)
        ->and((float) $company->rating_avg)->toBe(5.0);
});

it('blocks a second review on the same order with the real domain reason', function () {
    [, $buyer, , , $order] = crapiScene();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/review', ['rating' => 4])
        ->assertCreated();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/review', ['rating' => 2])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'review_not_eligible');

    expect(CompanyReview::where('order_id', $order->getKey())->count())->toBe(1);
});

it('refuses to review an order that has not completed yet', function () {
    [, $buyer, , , $order] = crapiScene(completed: false);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/review', ['rating' => 3])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'review_not_eligible');

    expect(CompanyReview::where('order_id', $order->getKey())->exists())->toBeFalse();
});

it('rejects an invalid rating with the standard validation envelope', function () {
    [, $buyer, , , $order] = crapiScene();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/review', ['rating' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rating', 'error.details');

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/review', ['rating' => 6])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rating', 'error.details');

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/review', ['rating' => 'not-a-number'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rating', 'error.details');

    expect(CompanyReview::where('order_id', $order->getKey())->exists())->toBeFalse();
});

it("404s another buyer's order on the write", function () {
    [, , , , $order] = crapiScene();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/review', ['rating' => 5])
        ->assertNotFound();
});

it('requires buyer auth to submit a review', function () {
    [, , , , $order] = crapiScene();

    $this->postJson('/api/v1/orders/'.$order->reference_code.'/review', ['rating' => 5])
        ->assertUnauthorized();
});

it('rejects a supplier account on the write', function () {
    [, , , , $order] = crapiScene();

    $supplierUser = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($supplierUser);

    $this->actingAs($supplierUser, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/review', ['rating' => 5])
        ->assertForbidden();
});
