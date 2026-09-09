<?php

use App\Domain\Trade\Commands\AwardQuoteCommand;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Support\Bus\CommandBus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/* Mirrors OrderTest.php's orderContext()/orderQuote() helpers, renamed to
   avoid the duplicate-global-function collision Pest would hit loading both
   files into one process. */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

function awardCommandContext(): array
{
    $company = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create();
    $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3',
    ]);

    RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    return [$rfq, $company];
}

function awardCommandQuote(Rfq $rfq, Company $company, float $unitPrice = 185.00): Quote
{
    $routing = RfqCompany::firstOrCreate(
        ['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()],
        ['status' => 'sent', 'routed_at' => now()],
    );

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'description' => 'Sawn timber',
        'quantity' => 100,
        'unit_price' => $unitPrice,
        'line_total' => Quote::lineTotal(100, $unitPrice),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    return $quote;
}

it('produces the same result via the CommandBus as calling QuoteService/OrderService directly', function () {
    [$rfq, $company] = awardCommandContext();
    $quote = awardCommandQuote($rfq, $company);

    $result = app(CommandBus::class)->dispatch(new AwardQuoteCommand(quoteId: $quote->getKey()));

    expect($result)->toBeInstanceOf(Quote::class);
    expect($result->status)->toBe(QuoteStatus::Accepted);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    expect($order->status)->toBe(OrderStatus::Awarded);
    expect((string) $order->total_amount)->toBe(Quote::lineTotal(100, 185.00));
    expect($rfq->fresh()->status->value)->toBe('closed');
});

it('rolls back the whole award when the underlying service rejects it', function () {
    [$rfq, $company] = awardCommandContext();
    $quote = awardCommandQuote($rfq, $company);
    $quote->update(['valid_until' => now()->subDay()->toDateString()]);

    expect(fn () => app(CommandBus::class)->dispatch(new AwardQuoteCommand(quoteId: $quote->getKey())))
        ->toThrow(RuntimeException::class, 'expired');

    expect(Order::where('quote_id', $quote->getKey())->exists())->toBeFalse();
    expect($quote->fresh()->status)->toBe(QuoteStatus::Submitted);
});
