<?php

use App\Models\Company;
use App\Models\Dispute;
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
 * Batch D / Task D2 — buyer account panel, RFQ wizard, disputes & order i18n.
 *
 * Every buyer-facing account page must render in BOTH locales without leaking a
 * raw, untranslated `messages.` / `enums.` key.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/** Build a real awarded order + its buyer, the way the platform does. */
function i18nAccountContext(): array
{
    $supplier = Company::factory()->publiclyVisible()->create();
    $buyer = User::factory()->create(['email' => 'i18n-buyer-'.uniqid().'@example.com']);

    $rfq = Rfq::factory()->approved()->create([
        'buyer_email' => $buyer->email,
        'user_id' => $buyer->getKey(),
        'title' => 'Sapele sawn timber for Q3',
    ]);
    $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3',
    ]);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $supplier->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $supplier->getKey(),
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

    return [$buyer, $rfq, $order];
}

dataset('accountLocales', ['en', 'fr']);

it('renders every buyer account surface in both locales with no raw key leaking', function (string $locale) {
    [$buyer, $rfq, $order] = i18nAccountContext();

    $dispute = app(\App\Services\DisputeService::class)->open(
        $order,
        $buyer,
        \App\Enums\DisputeCategory::Quality,
        'The delivered boards were not the agreed grade and several were split.',
    );

    $paths = [
        route('account.index'),
        route('account.rfqs'),
        route('account.quotes'),
        route('account.orders'),
        route('account.receipts'),
        route('account.messages'),
        route('account.orders.trade-assurance', ['order' => $order]),
        route('buyer.rfq.order', ['rfq' => $rfq]),
        route('disputes.index', ['order' => $order]),
        route('disputes.show', ['order' => $order, 'dispute' => $dispute]),
        '/request-quote/step/details',
    ];

    // An unrendered __('messages.<ns>.<key>') emits the literal key. Route names
    // like "account.messages.show" legitimately appear in Livewire snapshots, so
    // match the translation namespaces specifically rather than bare "messages.".
    $leaks = ['messages.account.', 'messages.rfq_wizard.', 'messages.dispute.', 'messages.order.', 'messages.enums.', 'messages.common.', 'messages.nav.'];

    foreach ($paths as $path) {
        $response = $this->actingAs($buyer)->withSession(['locale' => $locale])->get($path);
        expect($response->status())->toBe(200);

        foreach ($leaks as $leak) {
            expect($response->getContent())->not->toContain($leak);
        }
        expect($response->getContent())->not->toContain('enums.enums.');
    }
})->with('accountLocales');

it('translates a known account string per locale', function () {
    [$buyer] = i18nAccountContext();

    $this->actingAs($buyer)->withSession(['locale' => 'en'])->get(route('account.index'))
        ->assertSee('Welcome back');

    $this->actingAs($buyer)->withSession(['locale' => 'fr'])->get(route('account.index'))
        ->assertSee('Bon retour')
        ->assertDontSee('Welcome back');
});

it('renders the RFQ wizard step 1 in French without a raw key', function () {
    $html = $this->withSession(['locale' => 'fr'])->get('/request-quote/step/details')->getContent();

    expect($html)->not->toContain('messages.')
        ->and($html)->toContain('Détails de la demande'); // step label, fr
});

it('translates order status enum labels per locale', function () {
    [$buyer, $rfq] = i18nAccountContext();

    $this->actingAs($buyer)->withSession(['locale' => 'fr'])->get(route('buyer.rfq.order', ['rfq' => $rfq]))
        ->assertSee('Attribuée'); // OrderStatus::Awarded, fr
});
