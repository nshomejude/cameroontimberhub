<?php

use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Receipt;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Services\QuoteService;
use App\Services\ReceiptVerifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    RateLimiter::clear('receipt-verify-ip:127.0.0.1');
    RateLimiter::clear('receipt-verify-ip-hour:127.0.0.1');
});

/**
 * A real awarded order with distinctive, greppable buyer PII and line detail,
 * so a leak test can assert on exact strings rather than on a vague shape.
 */
function verifiableOrder(array $rfqOverrides = []): Order
{
    $company = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create(array_merge([
        'buyer_name' => 'Zephyrine Quillfeather',
        'buyer_company' => 'Quillfeather Hardwoods Holdings',
        'buyer_email' => 'zephyrine@quillfeather-secret.test',
        'buyer_country_code' => 'BE',
        'notes' => 'INTERNAL-BUYER-NOTE-DO-NOT-DISCLOSE',
    ], $rfqOverrides));

    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3']);

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
        'description' => 'Widgetwood Prime Balustrade Blanks',
        'dimensions' => '77mm x 777mm x 7777mm',
        'grade' => 'Grade Quux',
        'quantity' => 100,
        'unit' => 'm3',
        'unit_price' => 185.00,
        'line_total' => Quote::lineTotal(100, 185.00),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->accept($quote->refresh());

    return Order::where('quote_id', $quote->getKey())->firstOrFail();
}

/* -------------------------------------------------------------- happy path */

it('renders the verification form for anyone', function () {
    $this->get(route('receipts.verify'))
        ->assertOk()
        ->assertSee('Verify a receipt')
        ->assertSee('noindex', false);
});

it('verifies a genuine receipt by its printed number', function () {
    $order = verifiableOrder();
    $receipt = $order->receipt;

    $this->post(route('receipts.verify.store'), ['reference' => $receipt->receipt_number])
        ->assertOk()
        ->assertSee('AUTHENTIC')
        ->assertSee($receipt->receipt_number)
        ->assertSee($order->reference_code)
        ->assertSee($order->supplier_name);
});

it('verifies a genuine receipt by its token link', function () {
    $order = verifiableOrder();

    // The token URL redirects: the token must never end up in the rendered
    // page's canonical/og tags, its history entry, or an outbound Referer.
    $this->get($order->receipt->verificationUrl())
        ->assertRedirect(route('receipts.verify'))
        ->assertSessionHas('verified_receipt_id', $order->receipt->getKey());

    $this->followingRedirects()
        ->get($order->receipt->verificationUrl())
        ->assertOk()
        ->assertSee('AUTHENTIC')
        ->assertSee($order->receipt->receipt_number);
});

it('is case-insensitive about the printed number', function () {
    $order = verifiableOrder();

    $this->post(route('receipts.verify.store'), ['reference' => strtolower($order->receipt->receipt_number)])
        ->assertOk()
        ->assertSee('AUTHENTIC');
});

it('counts each check without disclosing anything extra', function () {
    $order = verifiableOrder();

    expect($order->receipt->verification_count)->toBe(0);

    $this->post(route('receipts.verify.store'), ['reference' => $order->receipt->receipt_number])->assertOk();

    expect($order->receipt->refresh()->verification_count)->toBe(1)
        ->and($order->receipt->verified_at)->not->toBeNull();
});

/* ----------------------------------------------------------------- failure */

it('reports a guessed or unknown reference as not recognised', function () {
    verifiableOrder();

    foreach (['RCT-2026-ZZZZZ', 'not-a-reference', str_repeat('a', 40)] as $guess) {
        $this->post(route('receipts.verify.store'), ['reference' => $guess])
            ->assertOk()
            ->assertSee('Not recognised')
            ->assertDontSee('AUTHENTIC');
    }
});

it('gives the same answer for an unknown token as for an unknown number', function () {
    verifiableOrder();

    $this->followingRedirects()
        ->get(route('receipts.verify.token', ['token' => str_repeat('Q', 40)]))
        ->assertOk()
        ->assertSee('Not recognised');
});

it('requires a reference', function () {
    $this->post(route('receipts.verify.store'), ['reference' => ''])
        ->assertSessionHasErrors('reference');
});

it('reports a withdrawn receipt as void rather than authentic', function () {
    $order = verifiableOrder();
    $order->receipt->update(['voided_at' => now(), 'void_reason' => 'Issued in error']);

    $this->post(route('receipts.verify.store'), ['reference' => $order->receipt->receipt_number])
        ->assertOk()
        ->assertSee('VOID')
        ->assertDontSee('AUTHENTIC');
});

/* ------------------------------------------------------- the privacy boundary */

it('exposes no buyer PII, no line items and no internal notes to a verifier', function () {
    $order = verifiableOrder();
    $receipt = $order->receipt;

    // Both entry points must be equally tight.
    $responses = [
        $this->post(route('receipts.verify.store'), ['reference' => $receipt->receipt_number]),
        $this->followingRedirects()->get($receipt->verificationUrl()),
    ];

    $forbidden = [
        // Buyer identity
        'Zephyrine Quillfeather',
        'Quillfeather Hardwoods Holdings',
        'zephyrine@quillfeather-secret.test',
        // Internal notes
        'INTERNAL-BUYER-NOTE-DO-NOT-DISCLOSE',
        // Line items and their commercial detail
        'Widgetwood Prime Balustrade Blanks',
        '77mm x 777mm x 7777mm',
        'Grade Quux',
        '185.00',
        // The token itself must never be echoed back into the page
        $receipt->verification_token,
    ];

    foreach ($responses as $response) {
        $response->assertOk()->assertSee('AUTHENTIC');

        foreach ($forbidden as $secret) {
            $response->assertDontSee($secret, false);
        }
    }
});

it('keeps the allow-list of disclosed facts explicit', function () {
    $order = verifiableOrder();

    $payload = app(ReceiptVerifier::class)->publicPayload($order->receipt);

    // Adding a key to publicPayload() is a disclosure decision. This test is
    // the tripwire: it fails until someone consciously updates the list.
    expect(array_keys($payload))->toEqualCanonicalizing([
        'issuer',
        'receipt_number',
        'order_reference',
        'issued_at',
        'amount',
        'currency',
        'supplier_name',
        'supplier_verified',
        'order_status',
        'status',
        'is_valid',
        'integrity_verified',
        'void_reason',
        'checked_at',
    ]);
});

it('never serialises the verification token off a receipt model', function () {
    $order = verifiableOrder();

    expect($order->receipt->toArray())->not->toHaveKey('verification_token')
        ->and(json_encode($order->receipt))->not->toContain($order->receipt->verification_token);
});

it('does not let a verifier walk from a receipt to the buyer order screens', function () {
    $order = verifiableOrder();

    // The verification page is public; the order screens behind it are not.
    $this->get(route('buyer.rfq.order', ['rfq' => $order->rfq_id]))->assertForbidden();
    $this->get(route('buyer.rfq.order.receipt', ['rfq' => $order->rfq_id]))->assertForbidden();
});

/* -------------------------------------------------------------- rate limits */

it('rate-limits number lookups so receipt numbers cannot be ground out', function () {
    $order = verifiableOrder();

    // The limiter is 10/minute per IP.
    for ($i = 0; $i < 10; $i++) {
        $this->post(route('receipts.verify.store'), ['reference' => 'RCT-2026-AAAA'.$i])->assertOk();
    }

    $this->post(route('receipts.verify.store'), ['reference' => $order->receipt->receipt_number])
        ->assertStatus(429);
});

it('rate-limits token lookups too', function () {
    verifiableOrder();

    for ($i = 0; $i < 10; $i++) {
        $this->get(route('receipts.verify.token', ['token' => str_pad((string) $i, 20, 'A')]))->assertRedirect();
    }

    $this->get(route('receipts.verify.token', ['token' => str_repeat('B', 20)]))->assertStatus(429);
});

/* ------------------------------------------------------------- token shape */

it('mints tokens that are long, random and unrelated to the receipt number', function () {
    $tokens = collect(range(1, 3))->map(fn () => verifiableOrder()->receipt->verification_token);

    expect($tokens->unique())->toHaveCount(3);

    foreach ($tokens as $token) {
        expect(strlen($token))->toBe(40)
            ->and($token)->toMatch('/^[A-Za-z0-9]{40}$/');
    }

    // Not sequential: consecutive receipts must not share a growing prefix.
    expect(substr($tokens[0], 0, 8))->not->toBe(substr($tokens[1], 0, 8));
});

it('does not expose receipts through any other public route', function () {
    $order = verifiableOrder();

    // A receipt id is not a public handle: nothing resolves one.
    expect(Receipt::count())->toBe(1);

    $this->get('/verify/'.$order->receipt->getKey())->assertNotFound();
});
