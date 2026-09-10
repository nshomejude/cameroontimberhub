<?php

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\ReputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Production-readiness plan Task C1 (blueprint §1.10): reputation figures are
 * rebuilt strictly from real rows; below a minimum sample a percentage stays
 * NULL and the public profile says "Not enough history yet".
 */

function reputationCompany(array $attributes = []): Company
{
    return Company::factory()->publiclyVisible()->create($attributes + [
        'orders_completed' => null,
        'on_time_delivery_percent' => null,
        'response_rate_percent' => null,
    ]);
}

function makeOrder(Company $company, array $overrides = []): Order
{
    $rfq = Rfq::factory()->approved()->create();
    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
    ]);

    return Order::create(array_merge([
        'quote_id' => $quote->getKey(),
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'reference_code' => 'ORD-'.Str::upper(Str::random(10)),
        'status' => OrderStatus::Completed->value,
        'buyer_name' => 'Test Buyer',
        'buyer_email' => 'buyer@example.test',
        'supplier_name' => $company->legal_name,
    ], $overrides));
}

function routeRfqTo(Company $company, ?string $routedAt = null): RfqCompany
{
    $rfq = Rfq::factory()->approved()->create();

    return RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => 'sent',
        'routed_at' => $routedAt ?? now(),
    ]);
}

// ---------------------------------------------------------------------------

it('computes on-time delivery and orders completed from real orders', function () {
    $company = reputationCompany();

    // 3 on-time, 1 late — all delivered, all completed.
    foreach ([-2, -1, 0] as $offset) {
        makeOrder($company, [
            'expected_delivery_at' => now()->toDateString(),
            'delivered_at' => now()->addDays($offset),
        ]);
    }
    makeOrder($company, [
        'expected_delivery_at' => now()->toDateString(),
        'delivered_at' => now()->addDays(5),
    ]);

    app(ReputationService::class)->recompute($company);
    $company->refresh();

    expect($company->on_time_delivery_percent)->toBe(75)
        ->and($company->orders_completed)->toBe(4)
        ->and($company->reputation_recomputed_at)->not->toBeNull();
});

it('counts disputes where the company is the respondent', function () {
    $company = reputationCompany();
    $order = makeOrder($company);

    Dispute::create([
        'order_id' => $order->getKey(),
        'category' => 'quality',
        'status' => 'opened',
        'description' => 'Timber does not match spec.',
        'raised_by_user_id' => User::factory()->create()->getKey(),
        'respondent_company_id' => $company->getKey(),
    ]);

    app(ReputationService::class)->recompute($company);

    expect($company->refresh()->disputes_count)->toBe(1);
});

it('keeps on-time delivery NULL below the minimum sample and shows the insufficient string', function () {
    $company = reputationCompany();

    foreach ([-1, 0] as $offset) {
        makeOrder($company, [
            'expected_delivery_at' => now()->toDateString(),
            'delivered_at' => now()->addDays($offset),
        ]);
    }

    app(ReputationService::class)->recompute($company);
    $company->refresh();

    expect($company->on_time_delivery_percent)->toBeNull();

    $html = $this->get(route('companies.show', $company->slug))->assertOk()->getContent();

    expect($html)
        ->toContain('Not enough history yet')
        ->not->toContain('0%');
});

it('does not touch a company with no orders', function () {
    $company = reputationCompany();

    $this->artisan('reputation:recompute')
        ->expectsOutputToContain('Recomputed reputation for 0 companies.')
        ->assertExitCode(0);

    $company->refresh();

    expect($company->reputation_recomputed_at)->toBeNull()
        ->and($company->orders_completed)->toBeNull();
});

it('computes response rate over a trailing 90-day window', function () {
    $company = reputationCompany();

    // 5 routings inside the window, 4 answered with a submitted quote.
    $recent = collect(range(1, 5))->map(fn () => routeRfqTo($company));

    $recent->take(4)->each(function (RfqCompany $routing) use ($company) {
        Quote::factory()->submitted()->create([
            'rfq_id' => $routing->rfq_id,
            'company_id' => $company->getKey(),
            'rfq_company_id' => $routing->getKey(),
            'submitted_at' => now(),
        ]);
    });

    // A 6th routing 100 days ago — excluded from numerator and denominator.
    $stale = routeRfqTo($company, now()->subDays(100)->toDateTimeString());
    Quote::factory()->submitted()->create([
        'rfq_id' => $stale->rfq_id,
        'company_id' => $company->getKey(),
        'rfq_company_id' => $stale->getKey(),
        'submitted_at' => now()->subDays(100),
    ]);

    app(ReputationService::class)->recompute($company);

    expect($company->refresh()->response_rate_percent)->toBe(80);
});

it('renders the "reputation as of" line after a recompute', function () {
    $company = reputationCompany();
    makeOrder($company);

    app(ReputationService::class)->recompute($company);

    $this->get(route('companies.show', $company->slug))
        ->assertOk()
        ->assertSee('Reputation figures as of '.$company->fresh()->reputation_recomputed_at->isoFormat('D MMM YYYY'));
});

it('recomputes every trading company through the command', function () {
    $company = reputationCompany();
    makeOrder($company);

    $this->artisan('reputation:recompute')
        ->expectsOutputToContain('Recomputed reputation for 1 companies.')
        ->assertExitCode(0);

    expect($company->refresh()->reputation_recomputed_at)->not->toBeNull();
});
