<?php

use App\Domain\Commerce\Commands\RecordPaymentCompletionCommand;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\ReferralEarningStatus;
use App\Enums\ReferralPayoutStatus;
use App\Filament\Resources\ReferralEarnings\Pages\ListReferralEarnings;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ReferralEarning;
use App\Models\ReferralPayout;
use App\Models\ReferralPayoutProfile;
use App\Models\User;
use App\Notifications\ReferralPayoutUpdatedNotification;
use App\Services\Referrals\ReferralPayoutService;
use App\Services\Referrals\ReferralService;
use App\Support\Bus\CommandBus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
 * Referral commission payouts (owner decision: payable via PayPal):
 * two-person PayPal Payouts flow, idempotency / never-pay-twice guards,
 * webhook + refresh transitions, manual MoMo/bank record, unconfigured
 * PayPal, referrer payout email (web + API), and the commission basis
 * excluding tax and passed-through provider fees. All PayPal calls faked.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    Notification::fake();
    config([
        'payments.paypal.client_id' => 'test-client',
        'payments.paypal.client_secret' => 'test-secret',
        'payments.paypal.webhook_id' => 'WH-TEST',
        'payments.paypal.environment' => 'sandbox',
    ]);
});

function rpoAdmin(): User
{
    $u = User::factory()->create();
    $u->assignRole('finance_officer');

    return $u;
}

function rpoEarning(array $attrs = [], ?string $paypalEmail = 'jean.dupont@example.com'): ReferralEarning
{
    $referrer = User::factory()->create();
    if ($paypalEmail !== null) {
        app(ReferralPayoutService::class)->setPaypalEmail($referrer, $paypalEmail);
    }

    return ReferralEarning::create(array_merge([
        'referrer_user_id' => $referrer->id,
        'referred_company_id' => Company::factory()->create()->id,
        'source_reference' => 'SUB-PAY-'.random_int(1000, 99999),
        'basis' => 'subscription',
        'base_amount' => 100,
        'rate_percent' => 10,
        'amount' => 10,
        'currency' => 'USD',
        'status' => ReferralEarningStatus::Approved,
        'approved_at' => now(),
    ], $attrs));
}

/** @param  array<string, mixed>  $batchGet */
function rpoFakePayPal(int $createStatus = 201, array $createBody = [], array $batchGet = []): void
{
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
        '*/v1/payments/payouts' => Http::response($createBody ?: [
            'batch_header' => ['payout_batch_id' => 'BATCH-1', 'batch_status' => 'PENDING'],
        ], $createStatus),
        '*/v1/payments/payouts/*' => Http::response($batchGet ?: [
            'batch_header' => ['payout_batch_id' => 'BATCH-1', 'batch_status' => 'PROCESSING'],
            'items' => [],
        ], 200),
    ]);
}

function rpoPayoutPosts(): array
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/payments/payouts'))
        ->values()
        ->all();
}

/** Request (admin A) + approve (admin B). */
function rpoPay(ReferralEarning $earning): ReferralPayout
{
    $service = app(ReferralPayoutService::class);
    $payout = $service->requestPayPalPayout($earning, rpoAdmin());

    return $service->approvePayPalPayout($payout, rpoAdmin(), request());
}

function rpoWebhook(string $event, ReferralPayout $payout, string $txStatus): TestResponse
{
    return test()->postJson('/payments/paypal/webhook', [
        'id' => 'WH-EVT-'.Str::random(6),
        'event_type' => $event,
        'resource' => [
            'payout_item_id' => 'ITEM-1',
            'transaction_id' => 'TX-1',
            'transaction_status' => $txStatus,
            'payout_batch_id' => $payout->payout_batch_id,
            'payout_item' => ['sender_item_id' => (string) $payout->referral_earning_id],
            'errors' => $txStatus === 'SUCCESS' ? null : ['name' => 'RECEIVER_UNREGISTERED', 'message' => 'Receiver is unregistered'],
        ],
    ]);
}

/* ---------------------------------------------------------- two-person */

it('enforces two-person control: the requester cannot approve, a different admin can', function () {
    rpoFakePayPal();
    $earning = rpoEarning();
    $service = app(ReferralPayoutService::class);
    $adminA = rpoAdmin();

    $payout = $service->requestPayPalPayout($earning, $adminA);
    expect($payout->status)->toBe(ReferralPayoutStatus::Requested)
        ->and($payout->sender_batch_id)->toBe('CTH-REFPAY-'.$payout->id);

    expect(fn () => $service->approvePayPalPayout($payout, $adminA, request()))
        ->toThrow(ValidationException::class, 'different administrator');
    expect(rpoPayoutPosts())->toBeEmpty();

    $payout = $service->approvePayPalPayout($payout->fresh(), rpoAdmin(), request());

    expect($payout->status)->toBe(ReferralPayoutStatus::Processing)
        ->and($payout->payout_batch_id)->toBe('BATCH-1')
        ->and($earning->fresh()->status)->toBe(ReferralEarningStatus::Approved);

    $posts = rpoPayoutPosts();
    expect($posts)->toHaveCount(1);
    $body = $posts[0]->data();
    expect($body['sender_batch_header']['sender_batch_id'])->toBe('CTH-REFPAY-'.$payout->id)
        ->and($body['items'][0]['recipient_type'])->toBe('EMAIL')
        ->and($body['items'][0]['receiver'])->toBe('jean.dupont@example.com')
        ->and($body['items'][0]['amount'])->toBe(['value' => '10.00', 'currency' => 'USD'])
        ->and($body['items'][0]['sender_item_id'])->toBe((string) $earning->id)
        ->and($posts[0]->header('PayPal-Request-Id')[0])->toBe('CTH-REFPAY-'.$payout->id);

    expect(Activity::where('log_name', 'referral_payout')->where('subject_id', $payout->id)->pluck('event')->all())
        ->toContain('requested', 'approved', 'submitted');
});

it('requires a recent 2FA step-up for the approver when staff 2FA is enforced', function () {
    rpoFakePayPal();
    config(['auth.require_staff_2fa' => true]);
    $service = app(ReferralPayoutService::class);
    $payout = $service->requestPayPalPayout(rpoEarning(), rpoAdmin());

    expect(fn () => $service->approvePayPalPayout($payout, rpoAdmin(), request()))
        ->toThrow(ValidationException::class, 'two-factor');
    expect($payout->fresh()->status)->toBe(ReferralPayoutStatus::Requested)
        ->and(rpoPayoutPosts())->toBeEmpty();
});

it('runs the two-person flow from the admin earnings table', function () {
    rpoFakePayPal();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $earning = rpoEarning();
    $adminA = rpoAdmin();
    $adminB = rpoAdmin();

    $this->actingAs($adminA);
    Livewire::test(ListReferralEarnings::class)
        ->callTableAction('requestPayout', $earning)
        ->assertNotified();
    Livewire::test(ListReferralEarnings::class)
        ->assertTableActionHidden('approvePayout', $earning);

    $this->actingAs($adminB);
    Livewire::test(ListReferralEarnings::class)
        ->assertTableActionVisible('approvePayout', $earning)
        ->callTableAction('approvePayout', $earning)
        ->assertNotified();

    expect($earning->fresh()->latestPayout->status)->toBe(ReferralPayoutStatus::Processing)
        ->and(rpoPayoutPosts())->toHaveCount(1);

    // A billing viewer without payments.manage sees no payout actions.
    $viewer = User::factory()->create();
    $viewer->assignRole('billing_officer');
    $this->actingAs($viewer);
    Livewire::test(ListReferralEarnings::class)
        ->assertTableActionHidden('refreshPayout', $earning)
        ->assertTableActionHidden('markPaidManually', $earning);
});

it('refuses payout actions to staff without payments.manage', function () {
    rpoFakePayPal();
    $viewer = User::factory()->create();
    $viewer->assignRole('billing_officer');
    $earning = rpoEarning();

    expect(fn () => app(ReferralPayoutService::class)->requestPayPalPayout($earning, $viewer))
        ->toThrow(ValidationException::class, 'payments.manage');
    expect(fn () => app(ReferralPayoutService::class)->markPaidManually($earning, $viewer, 'MOMO-12345'))
        ->toThrow(ValidationException::class, 'payments.manage');
});

it('bulk-requests payouts only for eligible earnings', function () {
    rpoFakePayPal();
    $eligible = rpoEarning();
    $noEmail = rpoEarning([], null);
    $pending = rpoEarning(['status' => ReferralEarningStatus::Pending]);
    $xaf = rpoEarning(['currency' => 'XAF', 'amount' => 10000]);

    $result = app(ReferralPayoutService::class)->requestMany(collect([$eligible, $noEmail, $pending, $xaf]), rpoAdmin());

    expect($result['ok'])->toBe(1)
        ->and($result['errors'])->toHaveCount(3)
        ->and(implode(' ', $result['errors']))->toContain('PayPal payout email', 'approved', 'XAF')
        ->and(ReferralPayout::count())->toBe(1);
});

/* ---------------------------------------------------- never pay twice */

it('never pays twice: live-payout guards, DB unique index, single approval wins', function () {
    rpoFakePayPal();
    $earning = rpoEarning();
    $service = app(ReferralPayoutService::class);
    $payout = $service->requestPayPalPayout($earning, rpoAdmin());

    expect(fn () => $service->requestPayPalPayout($earning, rpoAdmin()))
        ->toThrow(ValidationException::class, 'already has a payout');

    $service->approvePayPalPayout($payout, rpoAdmin(), request());
    expect(fn () => $service->approvePayPalPayout($payout->fresh(), rpoAdmin(), request()))
        ->toThrow(ValidationException::class, 'already been decided');
    expect(fn () => $service->markPaidManually($earning, rpoAdmin(), 'MOMO-12345'))
        ->toThrow(ValidationException::class);

    // Database guard, even bypassing the service.
    expect(fn () => ReferralPayout::create([
        'referral_earning_id' => $earning->id, 'method' => 'manual', 'status' => 'succeeded',
        'amount' => 10, 'currency' => 'USD',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('treats an unknown submission outcome safely and retries with the same sender_batch_id', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
        '*/v1/payments/payouts' => Http::sequence()
            ->push(['name' => 'INTERNAL_SERVICE_ERROR'], 500)
            ->push(['batch_header' => ['payout_batch_id' => 'BATCH-9', 'batch_status' => 'PENDING']], 201),
    ]);
    $earning = rpoEarning();

    $payout = rpoPay($earning);
    expect($payout->status)->toBe(ReferralPayoutStatus::Processing)
        ->and($payout->payout_batch_id)->toBeNull()
        ->and($payout->failure_reason)->toContain('outcome unknown');

    $payout = app(ReferralPayoutService::class)->refresh($payout);

    $posts = rpoPayoutPosts();
    expect($posts)->toHaveCount(2)
        ->and($posts[0]->data()['sender_batch_header']['sender_batch_id'])
        ->toBe($posts[1]->data()['sender_batch_header']['sender_batch_id'])
        ->and($payout->payout_batch_id)->toBe('BATCH-9');
});

it('does not mark a duplicate batch as failed (it may already be paid)', function () {
    rpoFakePayPal(400, ['name' => 'USER_BUSINESS_ERROR', 'message' => 'Batch already exists', 'details' => [['issue' => 'DUPLICATE_SENDER_BATCH_ID']]]);

    $payout = rpoPay(rpoEarning());

    expect($payout->status)->toBe(ReferralPayoutStatus::Processing)
        ->and($payout->provider_status)->toBe('DUPLICATE');
});

it('fails a payout PayPal definitively rejects and lets it be requested again', function () {
    rpoFakePayPal(422, ['name' => 'INSUFFICIENT_FUNDS', 'message' => 'Sender does not have sufficient funds.']);
    $earning = rpoEarning();

    $payout = rpoPay($earning);

    expect($payout->status)->toBe(ReferralPayoutStatus::Failed)
        ->and($payout->failure_reason)->toContain('INSUFFICIENT_FUNDS')
        ->and($earning->fresh()->status)->toBe(ReferralEarningStatus::Approved);
    Notification::assertSentTo($earning->referrer, ReferralPayoutUpdatedNotification::class);

    expect(app(ReferralPayoutService::class)->requestPayPalPayout($earning->fresh(), rpoAdmin())->status)
        ->toBe(ReferralPayoutStatus::Requested);
});

it('refuses approval when the referrer changed their PayPal email after the request', function () {
    rpoFakePayPal();
    $earning = rpoEarning();
    $service = app(ReferralPayoutService::class);
    $payout = $service->requestPayPalPayout($earning, rpoAdmin());
    $service->setPaypalEmail($earning->referrer, 'someone.else@example.com');

    expect(fn () => $service->approvePayPalPayout($payout, rpoAdmin(), request()))
        ->toThrow(ValidationException::class, 'changed their PayPal email');
    expect(rpoPayoutPosts())->toBeEmpty();
});

/* --------------------------------------------------- webhook / refresh */

it('marks the earning paid on PAYMENT.PAYOUTS-ITEM.SUCCEEDED and ignores the replay', function () {
    rpoFakePayPal();
    $earning = rpoEarning();
    $payout = rpoPay($earning);

    rpoWebhook('PAYMENT.PAYOUTS-ITEM.SUCCEEDED', $payout, 'SUCCESS')->assertOk()->assertJson(['status' => 'ok']);
    rpoWebhook('PAYMENT.PAYOUTS-ITEM.SUCCEEDED', $payout, 'SUCCESS')->assertOk();

    $payout->refresh();
    expect($payout->status)->toBe(ReferralPayoutStatus::Succeeded)
        ->and($payout->payout_item_id)->toBe('ITEM-1')
        ->and($payout->transaction_id)->toBe('TX-1')
        ->and($earning->fresh()->status)->toBe(ReferralEarningStatus::Paid)
        ->and($earning->fresh()->paid_at)->not->toBeNull();

    Notification::assertSentToTimes($earning->referrer, ReferralPayoutUpdatedNotification::class, 1);
    $n = new ReferralPayoutUpdatedNotification($payout);
    expect($n->via($earning->referrer))->toContain('mail', 'database')
        ->and($n->toArray($earning->referrer)['payout_status'])->toBe('paid');
});

it('handles FAILED, BLOCKED, UNCLAIMED → RETURNED webhooks; failures are re-payable', function (string $event, string $tx, ReferralPayoutStatus $expected) {
    rpoFakePayPal();
    $earning = rpoEarning();
    $payout = rpoPay($earning);

    rpoWebhook($event, $payout, $tx)->assertOk();

    expect($payout->fresh()->status)->toBe($expected)
        ->and($earning->fresh()->status)->toBe(ReferralEarningStatus::Approved);
    Notification::assertSentTo($earning->referrer, ReferralPayoutUpdatedNotification::class);

    if ($expected === ReferralPayoutStatus::Unclaimed) {
        // Still live: cannot be paid again while PayPal holds the money.
        expect(app(ReferralPayoutService::class)->paypalBlocker($earning->fresh()))->toContain('in progress');
        rpoWebhook('PAYMENT.PAYOUTS-ITEM.RETURNED', $payout, 'RETURNED')->assertOk();
        expect($payout->fresh()->status)->toBe(ReferralPayoutStatus::Failed);
    }

    expect(app(ReferralPayoutService::class)->paypalBlocker($earning->fresh()))->toBeNull();
})->with([
    'failed' => ['PAYMENT.PAYOUTS-ITEM.FAILED', 'FAILED', ReferralPayoutStatus::Failed],
    'blocked' => ['PAYMENT.PAYOUTS-ITEM.BLOCKED', 'BLOCKED', ReferralPayoutStatus::Failed],
    'unclaimed' => ['PAYMENT.PAYOUTS-ITEM.UNCLAIMED', 'UNCLAIMED', ReferralPayoutStatus::Unclaimed],
]);

it('reverts a paid earning when PayPal returns a succeeded payout', function () {
    rpoFakePayPal();
    $earning = rpoEarning();
    $payout = rpoPay($earning);
    rpoWebhook('PAYMENT.PAYOUTS-ITEM.SUCCEEDED', $payout, 'SUCCESS');
    // A plain FAILED after success is ignored …
    rpoWebhook('PAYMENT.PAYOUTS-ITEM.FAILED', $payout, 'FAILED');
    expect($earning->fresh()->status)->toBe(ReferralEarningStatus::Paid);

    // … a RETURNED (money came back) is not.
    rpoWebhook('PAYMENT.PAYOUTS-ITEM.RETURNED', $payout, 'RETURNED');
    expect($payout->fresh()->status)->toBe(ReferralPayoutStatus::Failed)
        ->and($earning->fresh()->status)->toBe(ReferralEarningStatus::Approved)
        ->and($earning->fresh()->paid_at)->toBeNull();
});

it('makes a commission payable again after PayPal returns / refunds a paid payout', function (string $event, string $tx) {
    rpoFakePayPal();
    $earning = rpoEarning();
    $first = rpoPay($earning);
    rpoWebhook('PAYMENT.PAYOUTS-ITEM.SUCCEEDED', $first, 'SUCCESS');
    expect($earning->fresh()->status)->toBe(ReferralEarningStatus::Paid);

    rpoWebhook($event, $first, $tx)->assertOk();

    $earning->refresh();
    expect($first->fresh()->status)->toBe(ReferralPayoutStatus::Failed)
        ->and($earning->status)->toBe(ReferralEarningStatus::Approved)
        ->and($earning->payoutStatus())->toBe('failed')
        ->and(app(ReferralPayoutService::class)->paypalBlocker($earning))->toBeNull();

    // A fresh attempt can now be raised and paid (the unique "one live /
    // succeeded attempt" index no longer blocks it) …
    $second = rpoPay($earning);
    expect($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe(ReferralPayoutStatus::Processing);
    $this->postJson('/payments/paypal/webhook', [
        'id' => 'WH-EVT-'.Str::random(6),
        'event_type' => 'PAYMENT.PAYOUTS-ITEM.SUCCEEDED',
        'resource' => [
            'payout_item_id' => 'ITEM-2',
            'transaction_id' => 'TX-2',
            'transaction_status' => 'SUCCESS',
            'sender_batch_id' => $second->sender_batch_id,
            'payout_item' => ['sender_item_id' => (string) $earning->id],
        ],
    ])->assertOk();
    expect($second->fresh()->status)->toBe(ReferralPayoutStatus::Succeeded)
        ->and($earning->fresh()->status)->toBe(ReferralEarningStatus::Paid)
        ->and(ReferralPayout::where('referral_earning_id', $earning->id)->count())->toBe(2);
})->with([
    'returned' => ['PAYMENT.PAYOUTS-ITEM.RETURNED', 'RETURNED'],
    'refunded' => ['PAYMENT.PAYOUTS-ITEM.REFUNDED', 'REFUNDED'],
]);

it('lets finance pay a reversed commission manually instead', function () {
    rpoFakePayPal();
    $earning = rpoEarning();
    $payout = rpoPay($earning);
    rpoWebhook('PAYMENT.PAYOUTS-ITEM.SUCCEEDED', $payout, 'SUCCESS');
    rpoWebhook('PAYMENT.PAYOUTS-ITEM.RETURNED', $payout, 'RETURNED');

    $manual = app(ReferralPayoutService::class)->markPaidManually($earning->fresh(), rpoAdmin(), 'MOMO TX 55120');

    expect($manual->method)->toBe('manual')
        ->and($earning->fresh()->status)->toBe(ReferralEarningStatus::Paid);
});

it('rejects payout webhooks whose signature does not verify', function () {
    // Registered first, so it wins over rpoFakePayPal()'s SUCCESS stub.
    Http::fake(['*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE'], 200)]);
    rpoFakePayPal();
    $earning = rpoEarning();
    $payout = rpoPay($earning);

    rpoWebhook('PAYMENT.PAYOUTS-ITEM.SUCCEEDED', $payout, 'SUCCESS')->assertStatus(400);

    expect($payout->fresh()->status)->toBe(ReferralPayoutStatus::Processing)
        ->and($earning->fresh()->status)->toBe(ReferralEarningStatus::Approved);
});

it('refreshes status from GET /v1/payments/payouts/{batch} (manual + scheduled)', function () {
    rpoFakePayPal(batchGet: [
        'batch_header' => ['payout_batch_id' => 'BATCH-1', 'batch_status' => 'SUCCESS'],
        'items' => [[
            'payout_item_id' => 'ITEM-7', 'transaction_id' => 'TX-7', 'transaction_status' => 'SUCCESS',
            'payout_item' => ['sender_item_id' => 'placeholder'],
        ]],
    ]);
    $earning = rpoEarning();
    rpoPay($earning);

    $this->artisan('referrals:refresh-payouts')->assertSuccessful();

    $payout = $earning->fresh()->latestPayout;
    expect($payout->status)->toBe(ReferralPayoutStatus::Succeeded)
        ->and($payout->payout_item_id)->toBe('ITEM-7')
        ->and($earning->fresh()->status)->toBe(ReferralEarningStatus::Paid);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/v1/payments/payouts/BATCH-1'));
});

/* ----------------------------------------------------------- manual */

it('marks a commission paid manually with a required reference note', function () {
    $earning = rpoEarning(['currency' => 'XAF', 'amount' => 10000], null);
    $service = app(ReferralPayoutService::class);
    $admin = rpoAdmin();

    expect(fn () => $service->markPaidManually($earning, $admin, '  '))
        ->toThrow(ValidationException::class, 'reference');

    $payout = $service->markPaidManually($earning, $admin, 'MOMO TX 884421');

    expect($payout->method)->toBe('manual')
        ->and($payout->status)->toBe(ReferralPayoutStatus::Succeeded)
        ->and($payout->reference_note)->toBe('MOMO TX 884421')
        ->and($earning->fresh()->status)->toBe(ReferralEarningStatus::Paid)
        ->and($earning->fresh()->paid_by)->toBe($admin->id);
    Notification::assertSentTo($earning->referrer, ReferralPayoutUpdatedNotification::class);
    expect(Activity::where('log_name', 'referral_payout')->where('event', 'manual_paid')->exists())->toBeTrue();

    expect(fn () => $service->markPaidManually($earning->fresh(), $admin, 'MOMO TX 2'))
        ->toThrow(ValidationException::class);
    expect(ReferralPayout::count())->toBe(1);
});

it('marks paid manually from the admin table', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $earning = rpoEarning();
    $this->actingAs(rpoAdmin());

    Livewire::test(ListReferralEarnings::class)
        ->callTableAction('markPaidManually', $earning, data: ['reference_note' => ''])
        ->assertHasTableActionErrors(['reference_note']);
    Livewire::test(ListReferralEarnings::class)
        ->callTableAction('markPaidManually', $earning, data: ['reference_note' => 'BANK-REF-001'])
        ->assertNotified();

    expect($earning->fresh()->status)->toBe(ReferralEarningStatus::Paid);
});

/* ------------------------------------------------------ unconfigured */

it('blocks PayPal payouts with a clear message when PayPal is not configured', function () {
    config(['payments.paypal.client_id' => null, 'payments.paypal.client_secret' => null]);
    Http::fake();
    $earning = rpoEarning();
    $service = app(ReferralPayoutService::class);

    expect($service->paypalBlocker($earning))->toContain('PayPal is not configured');
    expect(fn () => $service->requestPayPalPayout($earning, rpoAdmin()))
        ->toThrow(ValidationException::class, 'not configured');
    Http::assertNothingSent();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(rpoAdmin());
    Livewire::test(ListReferralEarnings::class)
        ->assertTableActionDisabled('requestPayout', $earning)
        ->assertTableActionVisible('markPaidManually', $earning);
});

/* ------------------------------------------------- commission basis */

it('computes the commission on the price excluding tax and passed-through fees', function () {
    $payment = new Payment([
        'amount' => 124.25, // 100 price + 19.25 tax + 5 provider fee
        'currency' => 'USD',
        'metadata' => ['tax' => ['subtotal' => '100.00', 'tax_amount' => '19.25', 'total' => '119.25']],
    ]);
    $payment->setAttribute('base_amount', 119.25); // excl. the passed-through fee

    expect(ReferralService::commissionBase($payment))->toBe(100.0);

    // No base_amount yet (fee pass-through not deployed) → amount minus tax.
    $noFee = new Payment(['amount' => 119.25, 'currency' => 'USD', 'metadata' => ['tax' => ['tax_amount' => '19.25']]]);
    expect(ReferralService::commissionBase($noFee))->toBe(100.0);

    // Untaxed, no fee → the amount itself.
    expect(ReferralService::commissionBase(new Payment(['amount' => 50000, 'currency' => 'XAF'])))->toBe(50000.0);
});

it('awards 10% of the tax-exclusive price on a real completed taxed payment', function () {
    $referrer = User::factory()->create(['email' => 'rpo'.uniqid().'@gmail.com']);
    $company = Company::factory()->create(['referred_by_user_id' => $referrer->id]);
    $plan = Plan::factory()->create(['billing_period' => 'monthly']);
    $payment = Payment::factory()->create([
        'company_id' => $company->id,
        'plan_id' => $plan->id,
        'provider' => PaymentProvider::MtnMomo,
        'status' => PaymentStatus::Pending,
        'amount' => 119250,
        'currency' => 'XAF',
        'provider_reference' => 'ref-'.Str::random(8),
        'metadata' => ['tax' => ['subtotal' => '100000.00', 'tax_amount' => '19250.00', 'total' => '119250.00', 'tax_rate' => 0.1925, 'tax_label' => 'TVA', 'rule_id' => null]],
    ]);

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), $payment->provider_reference));
    app(RelayOutboxEventsJob::class)->handle();

    $earning = ReferralEarning::where('payment_id', $payment->id)->firstOrFail();
    expect((float) $earning->base_amount)->toBe(100000.0)
        ->and((float) $earning->amount)->toBe(10000.0);
});

it('falls back to the invoice tax when the payment metadata has none', function () {
    $company = Company::factory()->create();
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'amount' => 118, 'currency' => 'USD', 'status' => PaymentStatus::Completed,
    ]);
    Invoice::query()->insert([
        'invoice_number' => 'INV-RPO-1', 'company_id' => $company->id, 'payment_id' => $payment->id,
        'status' => 'paid', 'currency' => 'USD', 'subtotal_amount' => 100, 'tax_amount' => 18, 'total_amount' => 118,
        'bill_to' => '{}', 'bill_from' => '{}', 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(ReferralService::commissionBase($payment->fresh()))->toBe(100.0);
});

/* ------------------------------------------------ referrer surfaces */

it('lets the referrer set a PayPal payout email via the API and shows it masked', function () {
    $user = User::factory()->create();
    $this->withToken($user->createToken('t')->plainTextToken);

    $this->patchJson('/api/v1/referrals/payout-settings', ['paypal_payout_email' => 'not-an-email'])
        ->assertUnprocessable();

    $this->patchJson('/api/v1/referrals/payout-settings', ['paypal_payout_email' => 'Jean.Dupont@Example.com'])
        ->assertOk()
        ->assertJsonPath('data.paypal_email_masked', 'je*********@example.com')
        ->assertJsonPath('data.has_paypal_email', true)
        ->assertJsonPath('data.paypal_available', true);

    expect(ReferralPayoutProfile::paypalEmailFor($user))->toBe('jean.dupont@example.com');

    $me = $this->getJson('/api/v1/referrals/me')->assertOk();
    expect($me->json('data.payout.paypal_email_masked'))->toBe('je*********@example.com')
        ->and(json_encode($me->json()))->not->toContain('jean.dupont@example.com');

    $this->patchJson('/api/v1/referrals/payout-settings', ['paypal_payout_email' => null])
        ->assertOk()->assertJsonPath('data.has_paypal_email', false);
});

it('exposes payout status on GET /api/v1/referrals/earnings', function () {
    rpoFakePayPal();
    $earning = rpoEarning();
    $payout = rpoPay($earning);
    $this->withToken($earning->referrer->createToken('t')->plainTextToken);

    $this->getJson('/api/v1/referrals/earnings')->assertOk()
        ->assertJsonPath('data.0.payout_status', 'processing');

    rpoWebhook('PAYMENT.PAYOUTS-ITEM.SUCCEEDED', $payout, 'SUCCESS');

    $this->getJson('/api/v1/referrals/earnings')->assertOk()
        ->assertJsonPath('data.0.status', 'paid')
        ->assertJsonPath('data.0.payout_status', 'paid')
        ->assertJsonPath('data.0.payout_method', 'paypal');
});

it('lets a buyer set the payout email on /account/settings and see payout status', function () {
    $buyer = User::factory()->create(['email' => 'rpo'.uniqid().'@example.com']);
    rpoEarning(['referrer_user_id' => $buyer->id]);

    $this->actingAs($buyer)->put('/account/settings/referral-payout', ['paypal_payout_email' => 'bad'])
        ->assertSessionHasErrorsIn('payout', ['paypal_payout_email']);

    $this->actingAs($buyer)->put('/account/settings/referral-payout', ['paypal_payout_email' => 'pay.me@example.com'])
        ->assertRedirect(route('account.settings').'#referral-payouts');

    $this->actingAs($buyer)->get('/account/settings')->assertOk()
        ->assertSee('pa****@example.com')
        ->assertDontSee('pay.me@example.com')
        ->assertSee('Approved — awaiting payout');
});
