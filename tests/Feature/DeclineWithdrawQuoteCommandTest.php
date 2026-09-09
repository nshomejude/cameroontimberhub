<?php

use App\Domain\Trade\Commands\DeclineQuoteCommand;
use App\Domain\Trade\Commands\WithdrawQuoteCommand;
use App\Domain\Trade\Events\QuoteDeclined;
use App\Domain\Trade\Events\QuoteWithdrawn;
use App\Enums\QuoteStatus;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\OutboxEvent;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\WebhookSubscription;
use App\Support\Bus\CommandBus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

function declineWithdrawContext(): array
{
    $company = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create();
    $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3',
    ]);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    return [$rfq, $company, $routing];
}

function declineWithdrawQuote(Rfq $rfq, Company $company, RfqCompany $routing): Quote
{
    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'quantity' => 100,
        'unit_price' => 185.00,
        'line_total' => Quote::lineTotal(100, 185.00),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    return $quote;
}

/* ------------------------------------------------------- regression parity */

it('DeclineQuoteCommand produces the same result as calling QuoteService::decline directly', function () {
    [$rfq, $company, $routing] = declineWithdrawContext();
    $quote = declineWithdrawQuote($rfq, $company, $routing);

    $result = app(CommandBus::class)->dispatch(new DeclineQuoteCommand(
        quoteId: $quote->getKey(),
        reason: 'Price above budget',
    ));

    expect($result)->toBeInstanceOf(Quote::class);
    expect($result->status)->toBe(QuoteStatus::Declined);
    expect($result->decline_reason)->toBe('Price above budget');
});

it('WithdrawQuoteCommand produces the same result as calling QuoteService::withdraw directly', function () {
    [$rfq, $company, $routing] = declineWithdrawContext();
    $quote = declineWithdrawQuote($rfq, $company, $routing);

    $result = app(CommandBus::class)->dispatch(new WithdrawQuoteCommand(
        quoteId: $quote->getKey(),
        reason: 'Sold out',
    ));

    expect($result)->toBeInstanceOf(Quote::class);
    expect($result->status)->toBe(QuoteStatus::Withdrawn);
});

it('rolls back the decline (and its outbox row) when the underlying transition is illegal', function () {
    [$rfq, $company, $routing] = declineWithdrawContext();
    $quote = declineWithdrawQuote($rfq, $company, $routing);
    $quote->update(['status' => QuoteStatus::Accepted]);

    expect(fn () => app(CommandBus::class)->dispatch(new DeclineQuoteCommand(
        quoteId: $quote->getKey(),
        reason: 'Too late',
    )))->toThrow(RuntimeException::class);

    expect($quote->fresh()->status)->toBe(QuoteStatus::Accepted);
    expect(OutboxEvent::where('event_type', 'quote.declined')->count())->toBe(0);
});

/* ---------------------------------------------------------------- outbox */

it('records the QuoteDeclined outbox event transactionally with the state change', function () {
    [$rfq, $company, $routing] = declineWithdrawContext();
    $quote = declineWithdrawQuote($rfq, $company, $routing);

    app(CommandBus::class)->dispatch(new DeclineQuoteCommand(
        quoteId: $quote->getKey(),
        reason: 'Not competitive',
    ));

    $row = OutboxEvent::where('event_type', 'quote.declined')->first();

    expect($row)->not->toBeNull();
    expect($row->aggregate_type)->toBe('Quote');
    expect((int) $row->aggregate_id)->toBe($quote->getKey());
    expect($row->payload['company_id'])->toBe($company->getKey());
    expect($row->payload['reason'])->toBe('Not competitive');
});

it('records the QuoteWithdrawn outbox event transactionally with the state change', function () {
    [$rfq, $company, $routing] = declineWithdrawContext();
    $quote = declineWithdrawQuote($rfq, $company, $routing);

    app(CommandBus::class)->dispatch(new WithdrawQuoteCommand(
        quoteId: $quote->getKey(),
        reason: 'Capacity issue',
    ));

    $row = OutboxEvent::where('event_type', 'quote.withdrawn')->first();

    expect($row)->not->toBeNull();
    expect($row->aggregate_type)->toBe('Quote');
    expect((int) $row->aggregate_id)->toBe($quote->getKey());
    expect($row->payload['company_id'])->toBe($company->getKey());
});

/* ----------------------------------------------------------------- relay */

it('RelayOutboxEventsJob correctly relays quote.declined and quote.withdrawn', function () {
    [$rfq, $company, $routing] = declineWithdrawContext();
    $quote = declineWithdrawQuote($rfq, $company, $routing);

    OutboxEvent::query()->create([
        'event_type' => 'quote.declined',
        'aggregate_type' => 'Quote',
        'aggregate_id' => (string) $quote->id,
        'payload' => ['quote_id' => $quote->id, 'company_id' => $company->id, 'reason' => 'x'],
        'attempts' => 0,
        'occurred_at' => now(),
    ]);

    OutboxEvent::query()->create([
        'event_type' => 'quote.withdrawn',
        'aggregate_type' => 'Quote',
        'aggregate_id' => (string) $quote->id,
        'payload' => ['quote_id' => $quote->id, 'company_id' => $company->id, 'reason' => null],
        'attempts' => 0,
        'occurred_at' => now(),
    ]);

    (new RelayOutboxEventsJob())->handle();

    expect(OutboxEvent::whereNull('published_at')->count())->toBe(0);
});

/* -------------------------------------------------------------- webhooks */

it('dispatches a DeliverWebhookJob for an active quote.declined subscription', function () {
    Bus::fake();

    [$rfq, $company, $routing] = declineWithdrawContext();
    $quote = declineWithdrawQuote($rfq, $company, $routing);

    $subscription = WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['quote.declined'],
        'secret_hash' => WebhookSubscription::hashSecret('secret'),
        'is_active' => true,
    ]);

    OutboxEvent::query()->create([
        'event_type' => 'quote.declined',
        'aggregate_type' => 'Quote',
        'aggregate_id' => (string) $quote->id,
        'payload' => ['quote_id' => $quote->id, 'company_id' => $company->id, 'reason' => 'x'],
        'attempts' => 0,
        'occurred_at' => now(),
    ]);

    (new RelayOutboxEventsJob())->handle();

    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($subscription) {
        return $job->subscriptionId === $subscription->id && $job->eventType === 'quote.declined';
    });
});

it('dispatches a DeliverWebhookJob for an active quote.withdrawn subscription', function () {
    Bus::fake();

    [$rfq, $company, $routing] = declineWithdrawContext();
    $quote = declineWithdrawQuote($rfq, $company, $routing);

    $subscription = WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['quote.withdrawn'],
        'secret_hash' => WebhookSubscription::hashSecret('secret'),
        'is_active' => true,
    ]);

    OutboxEvent::query()->create([
        'event_type' => 'quote.withdrawn',
        'aggregate_type' => 'Quote',
        'aggregate_id' => (string) $quote->id,
        'payload' => ['quote_id' => $quote->id, 'company_id' => $company->id, 'reason' => null],
        'attempts' => 0,
        'occurred_at' => now(),
    ]);

    (new RelayOutboxEventsJob())->handle();

    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($subscription) {
        return $job->subscriptionId === $subscription->id && $job->eventType === 'quote.withdrawn';
    });
});
