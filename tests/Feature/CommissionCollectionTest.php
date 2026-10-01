<?php

use App\Enums\CommissionDepositStatus;
use App\Enums\CommissionStatementStatus;
use App\Enums\CompanyStatus;
use App\Filament\Exporter\Resources\CommissionStatements\Pages\ListCommissionStatements as ExporterListStatements;
use App\Filament\Exporter\Resources\CommissionStatements\Pages\ViewCommissionStatement as ExporterViewStatement;
use App\Filament\Pages\CommissionReport;
use App\Filament\Resources\CommissionDeposits\Pages\ListCommissionDeposits;
use App\Filament\Resources\CommissionPaymentSettings\CommissionPaymentSettingResource;
use App\Filament\Resources\CommissionStatements\Pages\ListCommissionStatements as AdminListStatements;
use App\Models\CommissionDeposit;
use App\Models\CommissionPaymentSetting;
use App\Models\CommissionRule;
use App\Models\CommissionStatement;
use App\Models\Company;
use App\Models\NotificationPreference;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Notifications\CommissionDepositReviewedNotification;
use App\Notifications\CommissionStatementIssuedNotification;
use App\Notifications\CommissionStatementReminderNotification;
use App\Services\Commission\CommissionCalculator;
use App\Services\Commission\CommissionCollectionService;
use App\Services\Commission\CommissionStatementIssuer;
use App\Services\OrderService;
use App\Services\QuoteService;
use Carbon\CarbonImmutable;
use Database\Seeders\CommissionRuleSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * Marketplace-commission COLLECTION (owner decision 2026-10-01): monthly
 * statements per supplier/currency, manual MoMo / bank deposits reported by
 * suppliers and verified by finance, reminders/overdue, optional quoting
 * block, supplier API + exporter panel, admin queue, report and the dealer
 * commission rules.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(PlanSeeder::class);
    $this->seed(CommissionRuleSeeder::class);
    Mail::fake();
    Notification::fake();
    Storage::fake('documents');
    $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00:00'));
});

/* ------------------------------------------------------------------ helpers */

/** @return array{0: User, 1: Company} a supplier user + its company on the sell/free plan (3% dom / 5% intl). */
function ccolSupplier(CompanyStatus $status = CompanyStatus::Verified): array
{
    $user = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create(['country_code' => 'CM']);
    $company->forceFill(['plan_id' => Plan::where('slug', 'free')->value('id'), 'status' => $status])->save();
    $company->users()->attach($user, ['role' => 'owner']);

    return [$user, $company->refresh()];
}

/** A real order from $company, confirmed (= commission charged) now. Domestic 3% of qty × price. */
function ccolOrder(Company $company, string $currency = 'XAF', float $unitPrice = 10000, int $quantity = 10, string $destination = 'CM'): Order
{
    $rfq = Rfq::factory()->approved()->create(['buyer_country_code' => $destination, 'destination_country_code' => $destination]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => $quantity, 'unit' => 'm3']);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'status' => 'sent', 'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'rfq_company_id' => $routing->getKey(),
        'currency' => $currency,
    ]);
    $quote->items()->create([
        'description' => 'Sawn timber', 'quantity' => $quantity, 'unit' => 'm3',
        'unit_price' => $unitPrice, 'line_total' => Quote::lineTotal($quantity, $unitPrice),
    ]);
    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->accept($quote->fresh());
    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();
    app(OrderService::class)->confirm($order);

    return $order->refresh();
}

function ccolIssue(string $month = '2026-09'): array
{
    return app(CommissionStatementIssuer::class)->issueForMonth(CarbonImmutable::parse($month.'-01'));
}

function ccolFinance(): User
{
    $u = User::factory()->create();
    $u->assignRole('finance_officer');

    return $u;
}

function ccolStatement(Company $company, string $currency = 'XAF'): CommissionStatement
{
    return CommissionStatement::where('company_id', $company->getKey())->where('currency', $currency)
        ->where('status', '!=', 'void')->latest('id')->firstOrFail();
}

function ccolReport(CommissionStatement $s, User $by, array $overrides = []): CommissionDeposit
{
    return app(CommissionCollectionService::class)->report($s, $by, array_merge([
        'method' => 'mtn_momo',
        'amount' => (string) $s->outstanding(),
        'currency' => $s->currency->value,
        'transaction_reference' => 'MP'.random_int(100000, 999999),
        'paid_on' => now()->toDateString(),
    ], $overrides));
}

/* ------------------------------------------------------------ generation */

it('stamps commission_charged_at when the commission is charged', function () {
    [, $company] = ccolSupplier();
    $order = ccolOrder($company);

    expect($order->is_commission_charged)->toBeTrue()
        ->and($order->commission_charged_at->toDateTimeString())->toBe('2026-09-10 10:00:00')
        ->and($order->commission_amount)->toBe('3000.00');
});

it('issues one statement per company per currency for the previous month, with lines, number and due date', function () {
    [$user, $company] = ccolSupplier();
    $xaf1 = ccolOrder($company, 'XAF', 10000, 10);   // 3,000
    $xaf2 = ccolOrder($company, 'XAF', 20000, 10);   // 6,000
    $usd = ccolOrder($company, 'USD', 100, 10);      // 30.00
    [, $other] = ccolSupplier();
    ccolOrder($other, 'XAF', 10000, 10);

    // Charged in October: not on the September statement.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 01:15:00'));
    $october = ccolOrder($company, 'XAF', 50000, 10);

    $result = ccolIssue('2026-09');

    expect($result['issued'])->toHaveCount(3);

    $xaf = ccolStatement($company, 'XAF');
    expect($xaf->statement_number)->toStartWith('CTH-CS-2026-')
        ->and($xaf->total_amount)->toBe('9000.00')
        ->and($xaf->charges_amount)->toBe('9000.00')
        ->and($xaf->adjustments_amount)->toBe('0.00')
        ->and($xaf->status)->toBe(CommissionStatementStatus::Issued)
        ->and($xaf->period_start->toDateString())->toBe('2026-09-01')
        ->and($xaf->period_end->toDateString())->toBe('2026-09-30')
        ->and($xaf->due_date->toDateString())->toBe('2026-10-16')
        ->and($xaf->lines->pluck('order_id')->sort()->values()->all())->toBe(collect([$xaf1->id, $xaf2->id])->sort()->values()->all())
        ->and($xaf->lines->pluck('order_id'))->not->toContain($october->id)
        ->and($xaf->verifiesIntegrity())->toBeTrue();

    expect(ccolStatement($company, 'USD')->total_amount)->toBe('30.00');

    Notification::assertSentTo($user, CommissionStatementIssuedNotification::class);
    expect(Activity::where('log_name', 'commission_statement')->where('event', 'issued')->count())->toBe(3);
});

it('is idempotent per company + period + currency (service and artisan command)', function () {
    [, $company] = ccolSupplier();
    ccolOrder($company);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 01:15:00'));

    $this->artisan('commission:issue-statements')->assertSuccessful();
    $this->artisan('commission:issue-statements', ['--month' => '2026-09'])
        ->expectsOutputToContain('0 statement(s) issued')
        ->assertSuccessful();

    expect(CommissionStatement::count())->toBe(1);

    // An order whose September charge is only recorded after the statement
    // went out: a re-run never adds a second September statement; the order
    // is caught up on the next month's statement instead.
    $this->travelTo(CarbonImmutable::parse('2026-09-30 23:00:00'));
    $late = ccolOrder($company);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00'));

    expect(ccolIssue('2026-09')['existing'])->toBe(1)
        ->and(CommissionStatement::count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-01 01:15:00'));
    ccolIssue('2026-10');

    expect(CommissionStatement::count())->toBe(2)
        ->and(ccolStatement($company)->lines->pluck('order_id')->all())->toBe([$late->id]);
});

it('rejects a malformed or future --month', function () {
    $this->artisan('commission:issue-statements', ['--month' => '2026-9'])->assertFailed();
    $this->artisan('commission:issue-statements', ['--month' => '2027-01'])->assertFailed();
});

it('does not issue a zero-amount statement (order cancelled and credited back)', function () {
    [, $company] = ccolSupplier();
    $order = ccolOrder($company);
    app(OrderService::class)->transition($order, \App\Enums\OrderStatus::Cancelled, null, 'Buyer withdrew');

    expect($order->refresh()->commission_credited_amount)->toBe('3000.00');

    $result = ccolIssue('2026-09');

    expect($result['issued'])->toBe([])
        ->and(CommissionStatement::count())->toBe(0);
});

it('bills an order only once and carries a later credit as an adjustment on the next statement', function () {
    [, $company] = ccolSupplier();
    $order = ccolOrder($company);                 // 3,000 charged in September
    ccolIssue('2026-09');
    $september = ccolStatement($company);

    // October: a dispute credits 1,000 back on the already-billed order, and a new order is charged.
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00'));
    app(CommissionCalculator::class)->credit($order, '1000', 'Dispute resolution');
    $newOrder = ccolOrder($company, 'XAF', 20000, 10);   // 6,000

    ccolIssue('2026-10');
    $october = ccolStatement($company);

    expect($october->id)->not->toBe($september->id)
        ->and($october->charges_amount)->toBe('6000.00')
        ->and($october->adjustments_amount)->toBe('-1000.00')
        ->and($october->total_amount)->toBe('5000.00');

    $lines = $october->lines->keyBy('kind');
    expect($lines['charge']->order_id)->toBe($newOrder->id)
        ->and($lines['adjustment']->order_id)->toBe($order->id)
        ->and($lines['adjustment']->amount)->toBe('-1000.00');

    // The September statement is untouched, and the order is never billed again.
    expect($september->refresh()->total_amount)->toBe('3000.00')
        ->and(\App\Models\CommissionStatementLine::where('order_id', $order->id)->where('kind', 'charge')->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-15'));
    expect(ccolIssue('2026-11')['issued'])->toBe([]);
});

it('carries a net credit forward instead of issuing a negative statement', function () {
    [, $company] = ccolSupplier();
    $order = ccolOrder($company, 'XAF', 10000, 10); // 3,000
    ccolIssue('2026-09');

    $this->travelTo(CarbonImmutable::parse('2026-10-05'));
    app(CommissionCalculator::class)->credit($order, '2000', 'Refund');
    expect(ccolIssue('2026-10')['skipped_non_positive'])->toBe(1) // the credit alone: total -2,000 → not issued
        ->and(CommissionStatement::count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-05'));
    ccolOrder($company, 'XAF', 20000, 10); // 6,000
    $this->travelTo(CarbonImmutable::parse('2026-12-01'));
    ccolIssue('2026-11');

    expect(ccolStatement($company)->total_amount)->toBe('4000.00');
});

/* ------------------------------------------------------------- deposits */

it('lets a supplier report a deposit and finance confirm it partially then fully', function () {
    [$user, $company] = ccolSupplier();
    ccolOrder($company, 'XAF', 10000, 10); // 3,000
    ccolIssue('2026-09');
    $statement = ccolStatement($company);
    $finance = ccolFinance();
    $service = app(CommissionCollectionService::class);

    $first = ccolReport($statement, $user, ['amount' => '1000', 'transaction_reference' => 'mp 240911.1234']);
    expect($first->status)->toBe(CommissionDepositStatus::Pending)
        ->and($first->reference_key)->toBe('MP240911.1234')
        ->and($statement->refresh()->amount_paid)->toBe('0.00');

    $service->confirm($first, $finance, '1000');
    $statement->refresh();
    expect($statement->status)->toBe(CommissionStatementStatus::PartiallyPaid)
        ->and($statement->amount_paid)->toBe('1000.00')
        ->and($statement->outstanding())->toBe('2000.00');
    Notification::assertSentTo($user, CommissionDepositReviewedNotification::class);

    $second = ccolReport($statement, $user, ['method' => 'bank', 'amount' => '2000']);
    $service->confirm($second, $finance);
    $statement->refresh();

    expect($statement->status)->toBe(CommissionStatementStatus::Paid)
        ->and($statement->paid_at)->not->toBeNull()
        ->and($statement->verifiesIntegrity())->toBeTrue()
        ->and(Activity::where('log_name', 'commission_collection')->where('event', 'deposit_confirmed')->count())->toBe(2);

    // A paid statement takes no more deposits.
    expect(fn () => ccolReport($statement, $user, ['amount' => '1']))->toThrow(ValidationException::class);
});

it('rejects a deposit with a reason and notifies the supplier', function () {
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    ccolIssue('2026-09');
    $deposit = ccolReport(ccolStatement($company), $user, ['transaction_reference' => 'OM-1']);

    $rejected = app(CommissionCollectionService::class)->reject($deposit, ccolFinance(), 'No such transaction on our Orange Money account');

    expect($rejected->status)->toBe(CommissionDepositStatus::Rejected)
        ->and($rejected->rejection_reason)->toContain('No such transaction')
        ->and(ccolStatement($company)->amount_paid)->toBe('0.00');

    Notification::assertSentTo($user, CommissionDepositReviewedNotification::class,
        fn ($n) => $n->toArray($user)['deposit_status'] === 'rejected');

    // A rejected reference may be re-reported (e.g. a typo corrected later).
    expect(ccolReport(ccolStatement($company), $user, ['transaction_reference' => 'OM-1'])->status)->toBe(CommissionDepositStatus::Pending);
});

it('guards against reusing a transaction reference for the same method', function () {
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    [$user2, $company2] = ccolSupplier();
    ccolOrder($company2);
    ccolIssue('2026-09');

    ccolReport(ccolStatement($company), $user, ['transaction_reference' => 'TX-777', 'amount' => '100']);

    // Same reference + method, even normalised differently, even from another company → refused.
    expect(fn () => ccolReport(ccolStatement($company2), $user2, ['transaction_reference' => ' tx-777 ', 'amount' => '100']))
        ->toThrow(ValidationException::class, 'already been reported');

    // Same reference on a different method is a different transaction.
    expect(ccolReport(ccolStatement($company), $user, ['transaction_reference' => 'TX-777', 'method' => 'bank', 'amount' => '100'])->status)
        ->toBe(CommissionDepositStatus::Pending);
});

it('refuses a confirmation above the outstanding amount, by non-finance users, or twice', function () {
    [$user, $company] = ccolSupplier();
    ccolOrder($company); // 3,000
    ccolIssue('2026-09');
    $deposit = ccolReport(ccolStatement($company), $user, ['amount' => '3000']);
    $service = app(CommissionCollectionService::class);

    expect(fn () => $service->confirm($deposit, ccolFinance(), '3500'))->toThrow(ValidationException::class)
        ->and(fn () => $service->confirm($deposit, $user))->toThrow(AuthorizationException::class);

    $service->confirm($deposit, ccolFinance());
    expect(fn () => $service->confirm($deposit, ccolFinance()))->toThrow(ValidationException::class);
});

it('lets finance record a deposit received without a supplier report', function () {
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    ccolIssue('2026-09');
    $statement = ccolStatement($company);

    $deposit = app(CommissionCollectionService::class)->recordDeposit($statement, ccolFinance(), [
        'method' => 'orange_money', 'amount' => '3000', 'transaction_reference' => 'CI240915.0001', 'paid_on' => '2026-09-10',
    ]);

    expect($deposit->status)->toBe(CommissionDepositStatus::Confirmed)
        ->and($deposit->source)->toBe('admin')
        ->and($statement->refresh()->status)->toBe(CommissionStatementStatus::Paid);
    Notification::assertSentTo($user, CommissionDepositReviewedNotification::class);
});

it('voids a statement, releasing its orders so the period can be re-issued', function () {
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    ccolIssue('2026-09');
    $statement = ccolStatement($company);
    $pending = ccolReport($statement, $user, ['amount' => '100']);

    app(CommissionCollectionService::class)->void($statement, ccolFinance(), 'Issued with the wrong rate');

    expect($statement->refresh()->status)->toBe(CommissionStatementStatus::Void)
        ->and($statement->lines()->whereNull('voided_at')->count())->toBe(0)
        ->and($pending->refresh()->status)->toBe(CommissionDepositStatus::Rejected);

    ccolIssue('2026-09');
    $reissued = ccolStatement($company);
    expect($reissued->id)->not->toBe($statement->id)
        ->and($reissued->statement_number)->not->toBe($statement->statement_number)
        ->and($reissued->total_amount)->toBe('3000.00');

    // A statement with confirmed money on it cannot be voided.
    $deposit = ccolReport($reissued, $user, ['amount' => '500']);
    app(CommissionCollectionService::class)->confirm($deposit, ccolFinance());
    expect(fn () => app(CommissionCollectionService::class)->void($reissued->refresh(), ccolFinance(), 'oops'))
        ->toThrow(ValidationException::class);
});

/* ----------------------------------------------------- reminders / overdue */

it('sends one due-soon reminder and one overdue notice, flipping the status', function () {
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 01:15:00'));
    ccolIssue('2026-09');
    $statement = ccolStatement($company); // due 2026-10-16

    $this->travelTo(CarbonImmutable::parse('2026-10-12 07:20:00'));
    expect(app(CommissionCollectionService::class)->processDueDates())->toBe(['overdue' => 0, 'reminded' => 0]);

    $this->travelTo(CarbonImmutable::parse('2026-10-13 07:20:00'));
    $this->artisan('commission:process-statements')->assertSuccessful();
    $this->artisan('commission:process-statements')->assertSuccessful();
    Notification::assertSentToTimes($user, CommissionStatementReminderNotification::class, 1);

    $this->travelTo(CarbonImmutable::parse('2026-10-17 07:20:00'));
    expect(app(CommissionCollectionService::class)->processDueDates())->toBe(['overdue' => 1, 'reminded' => 0])
        ->and(app(CommissionCollectionService::class)->processDueDates())->toBe(['overdue' => 0, 'reminded' => 0])
        ->and($statement->refresh()->status)->toBe(CommissionStatementStatus::Overdue);

    Notification::assertSentTo($user, CommissionStatementReminderNotification::class,
        fn ($n) => $n->kind === CommissionStatementReminderNotification::OVERDUE);

    // A partial payment on an overdue statement keeps it overdue.
    $deposit = ccolReport($statement, $user, ['amount' => '1000']);
    app(CommissionCollectionService::class)->confirm($deposit, ccolFinance());
    expect($statement->refresh()->status)->toBe(CommissionStatementStatus::Overdue)
        ->and($statement->amount_paid)->toBe('1000.00');
});

it('respects notification preferences on every channel', function () {
    [$user, $company] = ccolSupplier();
    $pref = NotificationPreference::forUser($user);
    $pref->update(['channels' => ['push' => false, 'email' => true], 'types' => ['commission_statement' => false]]);

    ccolOrder($company);
    ccolIssue('2026-09');
    $notification = new CommissionStatementIssuedNotification(ccolStatement($company));

    expect($notification->via($user))->toBe([]);

    $pref->update(['types' => []]);
    expect($notification->via($user->fresh()))->toBe(['mail', 'database']);
});

/* ----------------------------------------------------------- enforcement */

function ccolQuotePayload(): array
{
    return ['currency' => 'XAF', 'items' => [['description' => 'Sapele', 'quantity' => 5, 'unit' => 'm3', 'unit_price' => 1000]]];
}

it('blocks after 15 overdue days by default (owner decision)', function () {
    expect(config('timber.commission.block_on_overdue_days'))->toBe(15);
});

it('does not block quoting on overdue commission when the flag is switched off', function () {
    config(['timber.commission.block_on_overdue_days' => null]);
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    ccolIssue('2026-09');
    $this->travelTo(CarbonImmutable::parse('2026-12-01'));

    expect(config('timber.commission.block_on_overdue_days'))->toBeNull()
        ->and(app(CommissionCollectionService::class)->isQuotingBlocked($company))->toBeFalse();

    $rfq = Rfq::factory()->approved()->create();
    RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'status' => 'sent', 'routed_at' => now()]);
    $this->actingAs($user, 'sanctum');

    $this->postJson("/api/v1/supplier/rfqs/{$rfq->reference_code}/quote", ccolQuotePayload())->assertCreated();
});

it('blocks new quotes with 409 commission_overdue once a statement is overdue beyond the configured days', function () {
    config(['timber.commission.block_on_overdue_days' => 7]);
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    $this->travelTo(CarbonImmutable::parse('2026-10-01'));
    ccolIssue('2026-09'); // due 2026-10-16

    $rfq = Rfq::factory()->approved()->create();
    RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'status' => 'sent', 'routed_at' => now()]);
    $this->actingAs($user, 'sanctum');

    // 7 days past due: still allowed (must be MORE than N days).
    $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00'));
    expect(app(CommissionCollectionService::class)->isQuotingBlocked($company))->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-10-24 12:00'));
    $this->postJson("/api/v1/supplier/rfqs/{$rfq->reference_code}/quote", ccolQuotePayload())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'commission_overdue');

    $this->getJson('/api/v1/supplier/commission/summary')
        ->assertOk()
        ->assertJsonPath('data.overdue', true)
        ->assertJsonPath('data.quoting_blocked', true);

    // Paying it lifts the block.
    $statement = ccolStatement($company);
    app(CommissionCollectionService::class)->confirm(ccolReport($statement, $user), ccolFinance());
    $this->postJson("/api/v1/supplier/rfqs/{$rfq->reference_code}/quote", ccolQuotePayload())->assertCreated();
});

/* ------------------------------------------------------------------- API */

it('lists, shows and summarises the caller company statements over the API', function () {
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    ccolOrder($company, 'USD', 100, 10);
    ccolIssue('2026-09');
    CommissionPaymentSetting::current()->update(['mtn_momo_number' => '+237 650 000 000', 'mtn_momo_name' => 'CTH SARL']);
    $this->actingAs($user, 'sanctum');

    $this->getJson('/api/v1/supplier/commission/statements')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.status', 'issued');

    $number = ccolStatement($company)->statement_number;

    $this->getJson("/api/v1/supplier/commission/statements/{$number}")
        ->assertOk()
        ->assertJsonPath('data.number', $number)
        ->assertJsonPath('data.amount_due', '3000.00')
        ->assertJsonPath('data.amount_due_formatted', 'XAF 3,000')
        ->assertJsonPath('data.lines.0.kind', 'charge')
        ->assertJsonPath('data.payment_instructions.methods.0.method', 'mtn_momo')
        ->assertJsonPath('data.payment_instructions.methods.0.details.number', '+237 650 000 000');

    $summary = $this->getJson('/api/v1/supplier/commission/summary')->assertOk()->json('data');
    expect(collect($summary['balances'])->pluck('outstanding', 'currency')->all())->toBe(['USD' => '30.00', 'XAF' => '3000.00'])
        ->and($summary['overdue'])->toBeFalse()
        ->and($summary['quoting_blocked'])->toBeFalse();
});

it('accepts a multipart deposit report with proof over the API', function () {
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    ccolIssue('2026-09');
    $number = ccolStatement($company)->statement_number;
    $this->actingAs($user, 'sanctum');

    $response = $this->post("/api/v1/supplier/commission/statements/{$number}/deposits", [
        'method' => 'mtn_momo', 'amount' => '3000', 'currency' => 'XAF',
        'transaction_reference' => 'MP260911.0001.A12345', 'paid_on' => '2026-09-10',
        'proof' => UploadedFile::fake()->image('receipt.jpg')->size(300),
    ], ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.has_proof', true);

    $deposit = CommissionDeposit::firstOrFail();
    Storage::disk('documents')->assertExists($deposit->proof_path);

    // Validation: currency mismatch, missing reference, oversize proof, duplicate reference.
    $this->postJson("/api/v1/supplier/commission/statements/{$number}/deposits", [
        'method' => 'bank', 'amount' => '10', 'currency' => 'USD', 'transaction_reference' => 'B-12', 'paid_on' => '2026-09-10',
    ])->assertStatus(422)->assertJsonValidationErrors(['currency'], 'error.details');

    $this->post("/api/v1/supplier/commission/statements/{$number}/deposits", [
        'method' => 'bank', 'amount' => '10', 'currency' => 'XAF', 'transaction_reference' => 'BANK-OK-1', 'paid_on' => '2026-09-10',
        'proof' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['proof'], 'error.details');

    $this->postJson("/api/v1/supplier/commission/statements/{$number}/deposits", [
        'method' => 'mtn_momo', 'amount' => '10', 'currency' => 'XAF', 'transaction_reference' => 'mp260911.0001.a12345', 'paid_on' => '2026-09-10',
    ])->assertStatus(422)->assertJsonValidationErrors(['transaction_reference'], 'error.details');
});

it('404s another company\'s statement on every supplier endpoint', function () {
    [, $company] = ccolSupplier();
    ccolOrder($company);
    ccolIssue('2026-09');
    $number = ccolStatement($company)->statement_number;

    [$intruder] = ccolSupplier();
    $this->actingAs($intruder, 'sanctum');

    $this->getJson("/api/v1/supplier/commission/statements/{$number}")->assertNotFound();
    $this->postJson("/api/v1/supplier/commission/statements/{$number}/deposits", [
        'method' => 'mtn_momo', 'amount' => '10', 'currency' => 'XAF', 'transaction_reference' => 'X-1', 'paid_on' => '2026-09-10',
    ])->assertNotFound();
    $this->getJson('/api/v1/supplier/commission/statements')->assertOk()->assertJsonCount(0, 'data');
    expect(CommissionDeposit::count())->toBe(0);
});

it('lets a pending-verification company view and pay its statements', function () {
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    ccolIssue('2026-09');
    $company->forceFill(['status' => CompanyStatus::Pending])->save();
    $number = ccolStatement($company)->statement_number;
    $this->actingAs($user, 'sanctum');

    $this->getJson("/api/v1/supplier/commission/statements/{$number}")->assertOk();
    $this->postJson("/api/v1/supplier/commission/statements/{$number}/deposits", [
        'method' => 'orange_money', 'amount' => '3000', 'currency' => 'XAF', 'transaction_reference' => 'OM-PENDING-1', 'paid_on' => '2026-09-10',
    ])->assertCreated();
});

/* -------------------------------------------------------------- Filament */

it('shows the supplier only its own statements in the exporter panel and lets it report a deposit', function () {
    Filament::setCurrentPanel(Filament::getPanel('exporter'));
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    [, $other] = ccolSupplier();
    ccolOrder($other);
    ccolIssue('2026-09');
    CommissionPaymentSetting::current()->update(['bank_name' => 'Afriland First Bank', 'bank_account_name' => 'CTH SARL', 'bank_account_number' => '10005-00001-123']);
    $mine = ccolStatement($company);
    $theirs = ccolStatement($other);

    $this->actingAs($user);

    Livewire::test(ExporterListStatements::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    Livewire::test(ExporterViewStatement::class, ['record' => $mine->getRouteKey()])
        ->assertOk()
        ->assertSee('Afriland First Bank')
        ->callAction('reportDeposit', [
            'method' => 'bank', 'amount' => '3000', 'transaction_reference' => 'AFB-778899', 'paid_on' => '2026-09-10',
        ])
        ->assertHasNoActionErrors();

    expect(CommissionDeposit::where('commission_statement_id', $mine->id)->value('transaction_reference'))->toBe('AFB-778899');

    $this->get(\App\Filament\Exporter\Resources\CommissionStatements\CommissionStatementResource::getUrl('view', ['record' => $theirs], panel: 'exporter'))
        ->assertNotFound();
});

it('lets finance confirm and reject deposits from the admin queue', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    ccolIssue('2026-09');
    $statement = ccolStatement($company);
    $a = ccolReport($statement, $user, ['amount' => '1000']);
    $b = ccolReport($statement, $user, ['amount' => '500']);

    $this->actingAs(ccolFinance());

    Livewire::test(ListCommissionDeposits::class)
        ->assertCanSeeTableRecords([$a, $b])
        ->callTableAction('confirmDeposit', $a, ['amount_received' => '1000'])
        ->callTableAction('rejectDeposit', $b, ['reason' => 'Reference not found on the MoMo statement']);

    expect($a->refresh()->status)->toBe(CommissionDepositStatus::Confirmed)
        ->and($b->refresh()->status)->toBe(CommissionDepositStatus::Rejected)
        ->and($statement->refresh()->status)->toBe(CommissionStatementStatus::PartiallyPaid);

    Livewire::test(AdminListStatements::class)
        ->assertCanSeeTableRecords([$statement])
        ->callTableAction('recordDeposit', $statement, [
            'method' => 'bank', 'amount' => '2000', 'transaction_reference' => 'WIRE-0001', 'paid_on' => '2026-09-10',
        ]);

    expect($statement->refresh()->status)->toBe(CommissionStatementStatus::Paid);
});

it('only accepts a pre-stored proof path that is a fresh upload in the company\'s own folder', function () {
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    [, $other] = ccolSupplier();
    ccolIssue('2026-09');
    $statement = ccolStatement($company);

    Storage::disk('documents')->put("commission-deposits/{$other->id}/foreign.pdf", 'x');
    Storage::disk('documents')->put('disputes/1/evidence.pdf', 'x');
    Storage::disk('documents')->put("commission-deposits/{$company->id}/01JFRESHUPLOAD.png", 'x');

    $service = app(CommissionCollectionService::class);
    $data = ['method' => 'mtn_momo', 'amount' => '10', 'currency' => 'XAF', 'paid_on' => '2026-09-10'];

    expect($service->report($statement, $user, $data + ['transaction_reference' => 'P-1'], "commission-deposits/{$other->id}/foreign.pdf")->proof_path)->toBeNull()
        ->and($service->report($statement, $user, $data + ['transaction_reference' => 'P-2'], '../../.env')->proof_path)->toBeNull();

    // A failed report never deletes a file it does not own.
    expect(fn () => $service->report($statement, $user, ['method' => 'nope'] + $data, 'disputes/1/evidence.pdf'))->toThrow(ValidationException::class);
    Storage::disk('documents')->assertExists('disputes/1/evidence.pdf');

    expect($service->report($statement, $user, $data + ['transaction_reference' => 'P-3'], "commission-deposits/{$company->id}/01JFRESHUPLOAD.png")->proof_path)
        ->toBe("commission-deposits/{$company->id}/01JFRESHUPLOAD.png");
});

it('lets finance set the payment instructions and view a statement in /admin', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    [, $company] = ccolSupplier();
    ccolOrder($company);
    ccolIssue('2026-09');
    $finance = ccolFinance();
    $this->actingAs($finance);

    $settings = CommissionPaymentSetting::current();

    Livewire::test(\App\Filament\Resources\CommissionPaymentSettings\Pages\ListCommissionPaymentSettings::class)
        ->callTableAction('edit', $settings, [
            'mtn_momo_number' => '+237 677 00 00 00', 'mtn_momo_name' => 'Cameroon Timber Hub SARL',
            'bank_name' => 'Ecobank', 'bank_account_name' => 'Cameroon Timber Hub SARL', 'bank_iban' => 'CM21 1000 2000 3000',
        ])
        ->assertHasNoTableActionErrors();

    $settings->refresh();
    expect($settings->updated_by)->toBe($finance->id)
        ->and(collect($settings->instructions())->pluck('method')->all())->toBe(['mtn_momo', 'bank'])
        ->and(Activity::where('event', 'payment_instructions_updated')->exists())->toBeTrue();

    Livewire::test(\App\Filament\Resources\CommissionStatements\Pages\ViewCommissionStatement::class, ['record' => ccolStatement($company)->getRouteKey()])
        ->assertOk()
        ->assertSee(ccolStatement($company)->statement_number);
});

it('streams a deposit proof to finance only', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    ccolIssue('2026-09');
    $deposit = app(CommissionCollectionService::class)->report(ccolStatement($company), $user, [
        'method' => 'mtn_momo', 'amount' => '3000', 'currency' => 'XAF', 'transaction_reference' => 'MP-PROOF-1', 'paid_on' => '2026-09-10',
    ], UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'));

    expect($deposit->proof_path)->toStartWith("commission-deposits/{$company->id}/");

    $this->actingAs(ccolFinance());
    Livewire::test(ListCommissionDeposits::class)
        ->callTableAction('downloadProof', $deposit)
        ->assertFileDownloaded('receipt.pdf');

    // A supplier user has no way into the admin queue at all.
    $this->actingAs($user);
    expect(\App\Filament\Resources\CommissionDeposits\CommissionDepositResource::canViewAny())->toBeFalse();
});

it('hides the confirm action and settings from staff without payments.manage', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    [$user, $company] = ccolSupplier();
    ccolOrder($company);
    ccolIssue('2026-09');
    $deposit = ccolReport(ccolStatement($company), $user);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('billing.view');
    $this->actingAs($viewer);

    Livewire::test(ListCommissionDeposits::class)
        ->assertCanSeeTableRecords([$deposit])
        ->assertTableActionHidden('confirmDeposit', $deposit)
        ->assertTableActionHidden('rejectDeposit', $deposit);

    expect(CommissionPaymentSettingResource::canViewAny())->toBeFalse();

    $this->actingAs(ccolFinance());
    expect(CommissionPaymentSettingResource::canViewAny())->toBeTrue();
});

it('reports collected vs outstanding per currency on the commission report', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    [$user, $company] = ccolSupplier();
    ccolOrder($company); // 3,000 XAF
    ccolIssue('2026-09');
    app(CommissionCollectionService::class)->confirm(ccolReport(ccolStatement($company), $user, ['amount' => '1000']), ccolFinance());
    ccolReport(ccolStatement($company), $user, ['amount' => '500']);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    $rows = Livewire::test(CommissionReport::class)->instance()->getCollectionRows();

    expect($rows->first())->toMatchArray([
        'currency' => 'XAF', 'statements' => 1, 'billed' => 'XAF 3,000', 'collected' => 'XAF 1,000',
        'outstanding' => 'XAF 2,000', 'overdue' => 'XAF 0', 'pending_deposits' => 'XAF 500', 'collection_rate' => '33.33%',
    ]);
});

/* -------------------------------------------------------- dealer rules */

it('seeds the dealer commission rules mapped onto the Free / Professional / Business bands', function () {
    $rules = CommissionRule::where('segment', 'deal')->get()->keyBy('plan_tier');

    expect($rules->keys()->sort()->values()->all())->toBe(['dealer-free', 'dealer-network', 'dealer-pro'])
        ->and($rules['dealer-free']->only(['domestic_rate', 'international_rate', 'cap_percent', 'cap_amount', 'cap_currency']))
        ->toBe(['domestic_rate' => '0.0300', 'international_rate' => '0.0500', 'cap_percent' => '0.0500', 'cap_amount' => '5000.00', 'cap_currency' => 'USD'])
        ->and($rules['dealer-pro']->only(['domestic_rate', 'international_rate', 'cap_percent']))
        ->toBe(['domestic_rate' => '0.0250', 'international_rate' => '0.0400', 'cap_percent' => '0.0400'])
        ->and($rules['dealer-network']->only(['domestic_rate', 'international_rate', 'cap_percent']))
        ->toBe(['domestic_rate' => '0.0200', 'international_rate' => '0.0300', 'cap_percent' => '0.0300']);
});

it('dealer data migration inserts missing dealer rules only', function () {
    CommissionRule::where('segment', 'deal')->where('plan_tier', '!=', 'dealer-pro')->delete();
    $pro = CommissionRule::where('plan_tier', 'dealer-pro')->first();

    $migration = require database_path('migrations/2026_10_01_180020_seed_dealer_commission_rules.php');
    $migration->up();
    $migration->up();

    expect(CommissionRule::where('segment', 'deal')->count())->toBe(3)
        ->and(CommissionRule::where('plan_tier', 'dealer-pro')->count())->toBe(1)
        ->and(CommissionRule::where('plan_tier', 'dealer-pro')->value('id'))->toBe($pro->id);
});

it('charges a dealer-pro supplier the Professional rate', function () {
    [, $company] = ccolSupplier();
    $company->forceFill(['plan_id' => Plan::where('slug', 'dealer-pro')->value('id')])->save();
    $company->subscriptions()->delete();

    $order = ccolOrder($company->refresh(), 'XAF', 10000, 10);

    expect($order->commission_rate)->toBe('0.0250')
        ->and($order->commission_amount)->toBe('2500.00');
});
