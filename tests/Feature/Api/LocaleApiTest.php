<?php

use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/**
 * `SetLocale` (App\Http\Middleware\SetLocale) resolves the API's locale from
 * `Accept-Language` when there is no session (every stateless API request),
 * falling back to `config('app.locale')` when the header is absent or names
 * an unsupported locale. `users.locale` does not exist yet (grepped — no
 * migration, no column), so the "authenticated user's saved preference wins"
 * half of the task is a real, separate follow-up and is not tested here.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/** Mirrors OrderApiTest's own apiOrder() helper — a real order via the award path. */
function localeApiOrder(?User $buyer = null): Order
{
    $supplierCompany = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create($buyer ? ['user_id' => $buyer->getKey()] : []);
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

    return Order::where('quote_id', $quote->getKey())->firstOrFail()->fresh();
}

it('defaults to English when Accept-Language is absent', function () {
    $buyer = User::factory()->create();
    $order = localeApiOrder($buyer);

    $label = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code)
        ->assertOk()
        ->json('data.status_label');

    expect($label)->toBe(__('messages.enums.order_status.awarded', [], 'en'));
});

it('stays English when Accept-Language: en is sent', function () {
    $buyer = User::factory()->create();
    $order = localeApiOrder($buyer);

    $label = $this->actingAs($buyer, 'sanctum')
        ->withHeaders(['Accept-Language' => 'en'])
        ->getJson('/api/v1/orders/'.$order->reference_code)
        ->assertOk()
        ->json('data.status_label');

    expect($label)->toBe(__('messages.enums.order_status.awarded', [], 'en'));
});

it('switches to French when Accept-Language: fr is sent', function () {
    $buyer = User::factory()->create();
    $order = localeApiOrder($buyer);

    $label = $this->actingAs($buyer, 'sanctum')
        ->withHeaders(['Accept-Language' => 'fr'])
        ->getJson('/api/v1/orders/'.$order->reference_code)
        ->assertOk()
        ->json('data.status_label');

    expect($label)
        ->toBe(__('messages.enums.order_status.awarded', [], 'fr'))
        ->not->toBe(__('messages.enums.order_status.awarded', [], 'en'));
});

it('matches a full locale tag like fr-FR against the base fr locale', function () {
    $buyer = User::factory()->create();
    $order = localeApiOrder($buyer);

    $label = $this->actingAs($buyer, 'sanctum')
        ->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8'])
        ->getJson('/api/v1/orders/'.$order->reference_code)
        ->assertOk()
        ->json('data.status_label');

    expect($label)->toBe(__('messages.enums.order_status.awarded', [], 'fr'));
});

it('falls back to English for an unsupported Accept-Language', function () {
    $buyer = User::factory()->create();
    $order = localeApiOrder($buyer);

    $label = $this->actingAs($buyer, 'sanctum')
        ->withHeaders(['Accept-Language' => 'de-DE'])
        ->getJson('/api/v1/orders/'.$order->reference_code)
        ->assertOk()
        ->json('data.status_label');

    expect($label)->toBe(__('messages.enums.order_status.awarded', [], 'en'));
});
