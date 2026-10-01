<?php

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Receipt;
use App\Models\ReferralEarning;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Subscription;
use App\Models\TaxRule;
use App\Models\TradeAssuranceAgreement;
use App\Models\User;
use App\Services\Commission\CommissionCalculator;
use App\Services\OrderService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/*
 * Launch money-flow suite (Cameroon Timber Hub).
 *
 * Drives every money path end-to-end through the real HTTP entry points with
 * the provider APIs faked, then asserts INVARIANTS rather than exact column
 * sets, so the suite keeps holding once provider-fee pass-through, seeded
 * commission rules and referral payouts land:
 *
 *   - one completed payment  => exactly one Active subscription, one paid
 *     invoice, one live receipt, at most one referral earning;
 *   - invoice lines + tax == invoice total == what the customer paid;
 *   - referral earning == rate% of the plan price EXCLUDING tax, rounded to
 *     the currency's real precision (XAF 0dp, USD 2dp);
 *   - a replayed webhook / return leg changes nothing;
 *   - a failed / cancelled / expired payment activates nothing;
 *   - XAF never leaves the platform with decimals; USD always with 2dp.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    Notification::fake();
    Mail::fake();

    config([
        'payments.mtn_momo.subscription_key' => 'sub-key',
        'payments.mtn_momo.api_user' => 'api-user',
        'payments.mtn_momo.api_key' => 'api-key',
        'payments.mtn_momo.currency' => 'XAF',
        'payments.orange_money.client_id' => 'om-client',
        'payments.orange_money.client_secret' => 'om-secret',
        'payments.orange_money.merchant_key' => 'om-merchant',
        'payments.orange_money.currency' => 'XAF',
        'payments.stripe.secret_key' => 'sk_test_mf',
        'payments.stripe.webhook_secret' => 'whsec_mf',
        'payments.paypal.environment' => 'sandbox',
        'payments.paypal.client_id' => 'pp-client',
        'payments.paypal.client_secret' => 'pp-secret',
        'payments.paypal.webhook_id' => 'pp-webhook',
        'payments.paypal.currency' => 'USD',
    ]);
});

afterEach(function () {
    \Stripe\ApiRequestor::setHttpClient(null);
});

/* =================================================================== helpers */

function mfRelay(): void
{
    app(RelayOutboxEventsJob::class)->handle();
}

function mfPlan(array $attrs = []): Plan
{
    $slug = 'mf-'.Str::lower(Str::random(8));

    return Plan::factory()->create(array_merge([
        'slug' => $slug,
        'name' => 'Plan '.$slug,
        'price_amount' => 50000,
        'price_currency' => 'XAF',
        'billing_period' => 'monthly',
        'segment' => 'sell',
        'is_active' => true,
    ], $attrs));
}

/** A buyer company + owner user, referred by a fresh referrer user. @return array{Company, User, User} */
function mfReferredBuyer(): array
{
    $referrer = User::factory()->create(['email' => 'referrer'.uniqid().'@gmail.com']);
    $user = User::factory()->create(['email' => 'buyer'.uniqid().'@yahoo.fr']);
    $company = Company::factory()->create(['country_code' => 'CM', 'created_by' => $user->id]);
    $company->users()->attach($user, ['role' => 'owner']);
    $company->forceFill(['referred_by_user_id' => $referrer->id])->saveQuietly();

    return [$company, $user, $referrer];
}

function mfLatestPayment(Company $company): Payment
{
    return Payment::where('company_id', $company->id)->latest('id')->firstOrFail();
}

/** Half-up rounding of a decimal string to $dp places, bcmath only. */
function mfRound(string $n, int $dp): string
{
    $shift = bcpow('10', (string) $dp);
    $scaled = bcadd(bcmul($n, $shift, 8), '0.5', 8);
    $int = explode('.', $scaled)[0];

    return bcdiv($int, $shift, $dp);
}

function mfDp(string $currency): int
{
    return in_array(strtoupper($currency), ['XAF', 'XOF'], true) ? 0 : 2;
}

function mfStripeSignature(string $payload, string $secret = 'whsec_mf'): string
{
    $t = time();

    return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$payload}", $secret);
}

function mfPostStripe(array $event): \Illuminate\Testing\TestResponse
{
    $payload = json_encode($event);

    return test()->call('POST', '/payments/stripe/webhook', [], [], [], [
        'HTTP_Stripe-Signature' => mfStripeSignature($payload),
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

/**
 * Swap Stripe's transport for an in-memory fake (the SDK uses its own cURL
 * client, not Laravel's Http facade). Records every request's params.
 */
function mfFakeStripe(): object
{
    $client = new class implements \Stripe\HttpClient\ClientInterface
    {
        /** @var list<array<string, mixed>> */
        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
        {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

            return [json_encode([
                'id' => 'cs_test_mf_'.count($this->requests),
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/pay/cs_test_mf',
            ]), 200, []];
        }
    };

    \Stripe\ApiRequestor::setHttpClient($client);

    return $client;
}

/**
 * The full set of post-payment invariants for one completed plan payment.
 * $planSubtotal is the plan price EXCLUDING tax (what referral commission is
 * assessed on and what the plan's invoice line carries).
 */
function mfAssertSettledOnce(Payment $payment, Plan $plan, string $planSubtotal, ?User $referrer): void
{
    $payment->refresh();
    $currency = strtoupper((string) $payment->currency);
    $dp = mfDp($currency);

    expect($payment->status)->toBe(PaymentStatus::Completed)
        ->and($payment->paid_at)->not->toBeNull();

    // ---- subscription: exactly one, Active, right plan/period/price ----
    $subs = Subscription::where('payment_id', $payment->id)->get();
    expect($subs)->toHaveCount(1);
    $sub = $subs->first();
    $expectedRenewal = $plan->billing_period === 'yearly'
        ? $sub->starts_at->copy()->addYear()
        : $sub->starts_at->copy()->addMonth();

    expect($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->plan_id)->toBe($plan->id)
        ->and($sub->billing_period)->toBe($plan->billing_period)
        ->and($sub->renews_at->toDateString())->toBe($expectedRenewal->toDateString())
        ->and(bccomp((string) $sub->price_amount, (string) $payment->amount, 2))->toBe(0)
        ->and($sub->price_currency)->toBe($payment->currency)
        ->and($payment->company->fresh()->plan_id)->toBe($plan->id)
        ->and(Subscription::where('company_id', $payment->company_id)->where('status', SubscriptionStatus::Active->value)->count())->toBe(1);

    // ---- invoice: exactly one, paid, arithmetic closes, chain verifies ----
    $invoices = Invoice::where('payment_id', $payment->id)->get();
    expect($invoices)->toHaveCount(1);
    $invoice = $invoices->first();
    $linesTotal = $invoice->lines->reduce(fn ($c, $l) => bcadd($c, (string) $l->line_total, 2), '0.00');

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->currency->value)->toBe($currency)
        ->and(bccomp(bcadd($linesTotal, (string) $invoice->tax_amount, 2), (string) $invoice->total_amount, 2))->toBe(0)
        ->and(bccomp(bcadd((string) $invoice->subtotal_amount, (string) $invoice->tax_amount, 2), (string) $invoice->total_amount, 2) <= 0)->toBeTrue()
        // the customer is invoiced exactly what they paid
        ->and(bccomp((string) $invoice->total_amount, (string) $payment->amount, 2))->toBe(0)
        // the plan line is the plan price excl. tax
        ->and($invoice->lines->contains(fn ($l) => bccomp((string) $l->line_total, $planSubtotal, 2) === 0))->toBeTrue()
        ->and($invoice->verifiesIntegrity())->toBeTrue();

    if ($dp === 0) {
        foreach (['subtotal_amount', 'tax_amount', 'total_amount'] as $col) {
            expect(bccomp((string) $invoice->{$col}, mfRound((string) $invoice->{$col}, 0), 2))->toBe(0, "invoice {$col} carries XAF decimals");
        }
    }

    test()->artisan('invoices:verify-chain')->assertExitCode(0);

    // ---- receipt: exactly one live, for the amount paid, chained ----
    $receipts = Receipt::where('payment_id', $payment->id)->whereNull('voided_at')->get();
    expect($receipts)->toHaveCount(1)
        ->and(bccomp((string) $receipts->first()->amount, (string) $payment->amount, 2))->toBe(0)
        ->and($receipts->first()->verifiesIntegrity())->toBeTrue();

    // ---- referral earning: once, rate% of price EXCLUDING tax ----
    $earnings = ReferralEarning::where('referred_company_id', $payment->company_id)->get();

    if ($referrer === null) {
        expect($earnings)->toHaveCount(0);

        return;
    }

    expect($earnings)->toHaveCount(1);
    $earning = $earnings->first();
    $rate = (string) $earning->rate_percent;
    $expected = mfRound(bcdiv(bcmul($planSubtotal, $rate, 8), '100', 8), $dp);

    expect($earning->payment_id)->toBe($payment->id)
        ->and($earning->referrer_user_id)->toBe($referrer->id)
        ->and(bccomp($rate, '10', 2))->toBe(0)
        ->and(bccomp((string) $earning->base_amount, $planSubtotal, 2))->toBe(0)
        ->and(bccomp((string) $earning->amount, $expected, 2))->toBe(0)
        ->and($earning->currency)->toBe($currency);
}

/** Snapshot of everything a replay must not change. @return array<string, mixed> */
function mfMoneySnapshot(Payment $payment): array
{
    $payment->refresh();

    return [
        'status' => $payment->status,
        'paid_at' => $payment->paid_at?->toIso8601String(),
        'subscriptions' => Subscription::where('company_id', $payment->company_id)->pluck('id')->all(),
        'active' => Subscription::where('company_id', $payment->company_id)->where('status', 'active')->pluck('id')->all(),
        'invoices' => Invoice::where('company_id', $payment->company_id)->pluck('id')->all(),
        'receipts' => Receipt::where('payment_id', $payment->id)->pluck('id')->all(),
        'earnings' => ReferralEarning::where('referred_company_id', $payment->company_id)->pluck('id')->all(),
        'completion_events' => OutboxEvent::where('event_type', 'payment.completed')->where('aggregate_id', (string) $payment->id)->count(),
    ];
}

function mfAssertNothingSettled(Payment $payment): void
{
    $payment->refresh();

    expect($payment->status)->not->toBe(PaymentStatus::Completed)
        ->and(Subscription::where('payment_id', $payment->id)->exists())->toBeFalse()
        ->and(Subscription::where('company_id', $payment->company_id)->where('status', 'active')->exists())->toBeFalse()
        ->and(Invoice::where('payment_id', $payment->id)->exists())->toBeFalse()
        ->and(Receipt::where('payment_id', $payment->id)->exists())->toBeFalse()
        ->and(ReferralEarning::where('payment_id', $payment->id)->exists())->toBeFalse()
        ->and(OutboxEvent::where('event_type', 'payment.completed')->where('aggregate_id', (string) $payment->id)->exists())->toBeFalse();
}

/* ===================================================== 1. subscriptions/gateway */

it('MTN MoMo: checkout -> push -> verified callback settles once (with TVA), XAF sent without decimals, replay is a no-op', function () {
    TaxRule::create(['name' => 'Cameroon TVA', 'jurisdiction' => 'CM', 'rate' => '0.1925', 'is_active' => true]);
    [$company, $user, $referrer] = mfReferredBuyer();
    $plan = mfPlan(['price_amount' => 50000, 'price_currency' => 'XAF']);

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok'], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => '59625', 'currency' => 'XAF'], 200),
        '*/collection/v1_0/requesttopay' => Http::response(null, 202),
    ]);

    $this->actingAs($user)->post(route('payments.checkout', $plan), [
        'provider' => PaymentProvider::MtnMomo->value,
        'msisdn' => '237670000000',
    ])->assertRedirect();

    $payment = mfLatestPayment($company);
    expect($payment->status)->toBe(PaymentStatus::Pending)
        // 50,000 + 19.25% TVA (9,625) — integer XAF
        ->and(bccomp((string) $payment->amount, '59625', 2))->toBe(0);

    Http::assertSent(function (HttpRequest $r) {
        if (! str_ends_with($r->url(), '/collection/v1_0/requesttopay') || $r->method() !== 'POST') {
            return false;
        }

        return $r['amount'] === '59625' && $r['currency'] === 'XAF';
    });

    // Callback before the outbox relays: nothing is activated yet.
    expect(Subscription::where('payment_id', $payment->id)->exists())->toBeFalse();

    $this->postJson(route('payments.mtn-momo.webhook'), ['referenceId' => $payment->provider_reference, 'status' => 'SUCCESSFUL'])->assertOk();
    mfRelay();

    mfAssertSettledOnce($payment, $plan, '50000.00', $referrer);
    $invoice = Invoice::where('payment_id', $payment->id)->first();
    expect((string) $invoice->subtotal_amount)->toBe('50000.00')
        ->and((string) $invoice->tax_amount)->toBe('9625.00')
        ->and((string) $invoice->total_amount)->toBe('59625.00');

    $before = mfMoneySnapshot($payment);
    $this->travel(5)->minutes();
    $this->postJson(route('payments.mtn-momo.webhook'), ['referenceId' => $payment->provider_reference])->assertOk();
    $this->postJson(route('payments.mtn-momo.webhook'), ['referenceId' => $payment->provider_reference])->assertOk();
    mfRelay();
    mfRelay();

    expect(mfMoneySnapshot($payment))->toBe($before);
});

it('Orange Money: checkout -> hosted page -> verified notify settles once, XAF integer amounts, referral rounds to whole francs', function () {
    [$company, $user, $referrer] = mfReferredBuyer();
    // 10% of 49,995 = 4,999.5 XAF -> must round to a whole franc (5,000).
    $plan = mfPlan(['price_amount' => 49995, 'price_currency' => 'XAF', 'billing_period' => 'yearly']);

    Http::fake([
        'https://api.orange.com/oauth/v3/token' => Http::response(['access_token' => 'tok'], 200),
        '*/webpayment' => Http::response(['payment_url' => 'https://webpayment.orange.test/pay', 'pay_token' => 'pt-mf-1'], 201),
        '*/transactionstatus' => Http::response(['status' => 'SUCCESS', 'amount' => 49995], 200),
    ]);

    $this->actingAs($user)->post(route('payments.checkout', $plan), [
        'provider' => PaymentProvider::OrangeMoney->value,
    ])->assertRedirect('https://webpayment.orange.test/pay');

    $payment = mfLatestPayment($company);
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->provider_reference)->toBe('pt-mf-1');

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/webpayment') && $r['amount'] === '49995' && $r['currency'] === 'XAF');

    // The browser return leg is informational only — it never settles.
    $this->get(route('payments.orange-money.return', $payment))->assertOk();
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);

    $this->post(route('payments.orange-money.notify'), ['pay_token' => 'pt-mf-1', 'status' => 'SUCCESS'])->assertOk();
    mfRelay();

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/transactionstatus') && $r['amount'] === '49995');

    mfAssertSettledOnce($payment, $plan, '49995.00', $referrer);
    expect((string) ReferralEarning::where('payment_id', $payment->id)->value('amount'))->toBe('5000.00');

    $before = mfMoneySnapshot($payment);
    $this->travel(5)->minutes();
    $this->post(route('payments.orange-money.notify'), ['pay_token' => 'pt-mf-1'])->assertOk();
    mfRelay();

    expect(mfMoneySnapshot($payment))->toBe($before);
});

it('Stripe: checkout session carries USD in cents, signed webhook settles once, replayed event is a no-op', function () {
    [$company, $user, $referrer] = mfReferredBuyer();
    $plan = mfPlan(['price_amount' => '19.99', 'price_currency' => 'USD', 'segment' => null]);
    $stripe = mfFakeStripe();

    $this->actingAs($user)->post(route('payments.checkout', $plan), [
        'provider' => PaymentProvider::Stripe->value,
    ])->assertRedirect('https://checkout.stripe.test/pay/cs_test_mf');

    $payment = mfLatestPayment($company);
    $params = $stripe->requests[0]['params'];
    expect($params['line_items'][0]['price_data']['unit_amount'])->toBe(1999)
        ->and($params['line_items'][0]['price_data']['currency'])->toBe('usd')
        ->and($payment->status)->toBe(PaymentStatus::Pending);

    $event = [
        'id' => 'evt_mf_1',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => $payment->provider_reference,
            'object' => 'checkout.session',
            'payment_intent' => 'pi_mf_1',
            'payment_status' => 'paid',
            'amount_total' => 1999,
            'currency' => 'usd',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]],
    ];

    mfPostStripe($event)->assertOk();
    mfRelay();

    mfAssertSettledOnce($payment, $plan, '19.99', $referrer);
    expect((string) ReferralEarning::where('payment_id', $payment->id)->value('amount'))->toBe('2.00');

    $before = mfMoneySnapshot($payment);
    $this->travel(5)->minutes();
    mfPostStripe($event)->assertOk();
    // A late "expired" for the same session must not un-complete it either.
    mfPostStripe(array_replace_recursive($event, ['id' => 'evt_mf_2', 'type' => 'checkout.session.expired']))->assertOk();
    mfRelay();

    expect(mfMoneySnapshot($payment))->toBe($before);
});

it('Stripe: XAF is a zero-decimal currency — unit_amount is the franc amount, never x100', function () {
    [$company, $user] = mfReferredBuyer();
    $plan = mfPlan(['price_amount' => 50000, 'price_currency' => 'XAF', 'segment' => null]);
    $stripe = mfFakeStripe();

    $this->actingAs($user)->post(route('payments.checkout', $plan), [
        'provider' => PaymentProvider::Stripe->value,
    ])->assertRedirect();

    expect($stripe->requests[0]['params']['line_items'][0]['price_data']['unit_amount'])->toBe(50000)
        ->and($stripe->requests[0]['params']['line_items'][0]['price_data']['currency'])->toBe('xaf');
});

it('Stripe: a completed session that is not yet paid (async method) does not settle', function () {
    [$company] = mfReferredBuyer();
    $plan = mfPlan(['price_amount' => '19.99', 'price_currency' => 'USD', 'segment' => null]);
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::Stripe,
        'amount' => '19.99', 'currency' => 'USD', 'provider_reference' => 'cs_unpaid', 'status' => PaymentStatus::Pending,
    ]);

    mfPostStripe([
        'id' => 'evt_unpaid',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_unpaid', 'object' => 'checkout.session', 'payment_intent' => 'pi_unpaid',
            'payment_status' => 'unpaid', 'amount_total' => 1999, 'currency' => 'usd',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]],
    ])->assertOk();
    mfRelay();

    mfAssertNothingSettled($payment);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('Stripe: a paid session whose amount does not match the payment does not settle', function () {
    [$company] = mfReferredBuyer();
    $plan = mfPlan(['price_amount' => '19.99', 'price_currency' => 'USD', 'segment' => null]);
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::Stripe,
        'amount' => '19.99', 'currency' => 'USD', 'provider_reference' => 'cs_cheap', 'status' => PaymentStatus::Pending,
    ]);

    mfPostStripe([
        'id' => 'evt_cheap',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_cheap', 'object' => 'checkout.session', 'payment_intent' => 'pi_cheap',
            'payment_status' => 'paid', 'amount_total' => 100, 'currency' => 'usd',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]],
    ])->assertOk();
    mfRelay();

    mfAssertNothingSettled($payment);
});

it('PayPal: create order (USD 2dp, with TVA) -> signed return leg captures -> settles once; replayed return and webhook are no-ops', function () {
    TaxRule::create(['name' => 'Cameroon TVA', 'jurisdiction' => 'CM', 'rate' => '0.1925', 'is_active' => true]);
    [$company, $user, $referrer] = mfReferredBuyer();
    // 29.99 * 19.25% = 5.773 -> 5.77 ; total 35.76 ; referral 10% of 29.99 = 2.999 -> 3.00
    $plan = mfPlan(['price_amount' => '29.99', 'price_currency' => 'USD', 'segment' => 'export']);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
        '*/v2/checkout/orders/ORDER-MF-1/capture' => Http::response([
            'id' => 'ORDER-MF-1',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'payments' => ['captures' => [[
                    'id' => 'CAPTURE-MF-1',
                    'status' => 'COMPLETED',
                    'amount' => ['value' => '35.76', 'currency_code' => 'USD'],
                ]]],
            ]],
        ], 201),
        '*/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-MF-1',
            'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/approve/ORDER-MF-1']],
        ], 201),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
    ]);

    $this->actingAs($user)->post(route('payments.checkout', $plan), [
        'provider' => PaymentProvider::PayPal->value,
    ])->assertRedirect('https://paypal.test/approve/ORDER-MF-1');

    $payment = mfLatestPayment($company);
    expect((string) $payment->amount)->toBe('35.76');

    Http::assertSent(function (HttpRequest $r) {
        if (! str_ends_with($r->url(), '/v2/checkout/orders')) {
            return false;
        }

        $amount = $r['purchase_units'][0]['amount'];

        return $amount['value'] === '35.76' && $amount['currency_code'] === 'USD';
    });

    $returnUrl = URL::temporarySignedRoute('payments.paypal.return', now()->addHour(), ['payment' => $payment->id]).'&token=ORDER-MF-1&PayerID=PAYER1';
    $this->get($returnUrl)->assertOk();
    mfRelay();

    mfAssertSettledOnce($payment, $plan, '29.99', $referrer);
    $invoice = Invoice::where('payment_id', $payment->id)->first();
    expect((string) $invoice->tax_amount)->toBe('5.77')
        ->and((string) $invoice->total_amount)->toBe('35.76')
        ->and((string) ReferralEarning::where('payment_id', $payment->id)->value('amount'))->toBe('3.00');

    $before = mfMoneySnapshot($payment);
    $this->travel(5)->minutes();

    // Double-click on the return link, then PayPal's async capture webhook.
    $this->get($returnUrl);
    $this->postJson(route('payments.paypal.webhook'), [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => 'CAPTURE-MF-1',
            'amount' => ['value' => '35.76', 'currency_code' => 'USD'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-MF-1']],
        ],
    ])->assertOk();
    // A stray cancel link after completion must not fail the payment.
    $this->get(URL::temporarySignedRoute('payments.paypal.cancel', now()->addHour(), ['payment' => $payment->id]));
    mfRelay();

    expect(mfMoneySnapshot($payment))->toBe($before);
    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), '/capture'))->count())->toBe(1);
});

it('a renewal (second completed payment) activates a fresh term but earns no second referral commission', function () {
    [$company, $user, $referrer] = mfReferredBuyer();
    $plan = mfPlan(['price_amount' => 50000]);

    $pay = function () use ($company, $plan): Payment {
        $payment = Payment::factory()->create([
            'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::MtnMomo,
            'amount' => 50000, 'currency' => 'XAF', 'status' => PaymentStatus::Pending,
            'provider_reference' => 'ref-'.Str::random(10),
        ]);
        Http::fake([
            '*/collection/token/' => Http::response(['access_token' => 'tok'], 200),
            '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => '50000'], 200),
        ]);
        test()->postJson(route('payments.mtn-momo.webhook'), ['referenceId' => $payment->provider_reference])->assertOk();
        mfRelay();

        return $payment->fresh();
    };

    $first = $pay();
    $second = $pay();

    expect(Subscription::where('company_id', $company->id)->where('status', 'active')->count())->toBe(1)
        ->and(Subscription::where('company_id', $company->id)->where('status', 'active')->value('payment_id'))->toBe($second->id)
        ->and(Invoice::where('company_id', $company->id)->count())->toBe(2)
        ->and(Receipt::whereIn('payment_id', [$first->id, $second->id])->count())->toBe(2)
        ->and(ReferralEarning::where('referred_company_id', $company->id)->count())->toBe(1)
        ->and(ReferralEarning::where('referred_company_id', $company->id)->value('payment_id'))->toBe($first->id);
});

it('a company with no referrer earns nobody a commission', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['country_code' => 'CM']);
    $company->users()->attach($user, ['role' => 'owner']);
    $plan = mfPlan();

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok'], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => '50000'], 200),
        '*/collection/v1_0/requesttopay' => Http::response(null, 202),
    ]);

    $this->actingAs($user)->post(route('payments.checkout', $plan), ['provider' => 'mtn_momo', 'msisdn' => '237670000000']);
    $payment = mfLatestPayment($company);
    $this->postJson(route('payments.mtn-momo.webhook'), ['referenceId' => $payment->provider_reference])->assertOk();
    mfRelay();

    mfAssertSettledOnce($payment, $plan, '50000.00', null);
});

/* ======================================= 2. failed / cancelled / expired paths */

it('MoMo: a FAILED / REJECTED / TIMEOUT confirmation never activates anything', function (string $status) {
    [$company] = mfReferredBuyer();
    $plan = mfPlan();
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::MtnMomo,
        'amount' => 50000, 'currency' => 'XAF', 'status' => PaymentStatus::Pending, 'provider_reference' => 'ref-fail-'.$status,
    ]);

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok'], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => $status, 'amount' => '50000'], 200),
    ]);

    // The unauthenticated payload claims success; MTN's own answer wins.
    $this->postJson(route('payments.mtn-momo.webhook'), ['referenceId' => $payment->provider_reference, 'status' => 'SUCCESSFUL'])->assertOk();
    mfRelay();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
    mfAssertNothingSettled($payment);
})->with(['FAILED', 'REJECTED', 'TIMEOUT']);

it('MoMo: SUCCESSFUL with a mismatched amount is refused', function () {
    [$company] = mfReferredBuyer();
    $plan = mfPlan();
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::MtnMomo,
        'amount' => 50000, 'currency' => 'XAF', 'status' => PaymentStatus::Pending, 'provider_reference' => 'ref-short',
    ]);

    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 'tok'], 200),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => '500'], 200),
    ]);

    $this->postJson(route('payments.mtn-momo.webhook'), ['referenceId' => 'ref-short'])->assertStatus(409);
    mfRelay();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);
    mfAssertNothingSettled($payment);
});

it('Orange: EXPIRED / CANCELLED / FAILED never activate anything', function (string $status) {
    [$company] = mfReferredBuyer();
    $plan = mfPlan();
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::OrangeMoney,
        'amount' => 50000, 'currency' => 'XAF', 'status' => PaymentStatus::Pending, 'provider_reference' => 'pt-'.$status,
    ]);

    Http::fake([
        'https://api.orange.com/oauth/v3/token' => Http::response(['access_token' => 'tok'], 200),
        '*/transactionstatus' => Http::response(['status' => $status], 200),
    ]);

    $this->post(route('payments.orange-money.notify'), ['pay_token' => 'pt-'.$status, 'status' => 'SUCCESS'])->assertOk();
    mfRelay();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
    mfAssertNothingSettled($payment);
})->with(['EXPIRED', 'CANCELLED', 'FAILED']);

it('Stripe: an expired session or failed intent fails the payment and activates nothing', function (string $type) {
    [$company] = mfReferredBuyer();
    $plan = mfPlan(['price_amount' => '19.99', 'price_currency' => 'USD', 'segment' => null]);
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::Stripe,
        'amount' => '19.99', 'currency' => 'USD', 'provider_reference' => 'cs_exp', 'status' => PaymentStatus::Pending,
    ]);

    mfPostStripe([
        'id' => 'evt_'.$type,
        'type' => $type,
        'data' => ['object' => ['id' => 'cs_exp', 'metadata' => ['payment_id' => (string) $payment->id]]],
    ])->assertOk();
    mfRelay();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
    mfAssertNothingSettled($payment);
})->with(['checkout.session.expired', 'payment_intent.payment_failed']);

it('Stripe/PayPal: the buyer cancel leg fails a pending payment and activates nothing', function () {
    [$company] = mfReferredBuyer();
    $plan = mfPlan(['price_amount' => '19.99', 'price_currency' => 'USD', 'segment' => null]);
    $stripePayment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::Stripe,
        'amount' => '19.99', 'currency' => 'USD', 'status' => PaymentStatus::Pending,
    ]);
    $paypalPayment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::PayPal,
        'amount' => '19.99', 'currency' => 'USD', 'status' => PaymentStatus::Pending, 'provider_reference' => 'ORDER-X',
    ]);

    $this->get(URL::temporarySignedRoute('payments.stripe.cancel', now()->addHour(), ['payment' => $stripePayment->id]))->assertRedirect();
    $this->get(URL::temporarySignedRoute('payments.paypal.cancel', now()->addHour(), ['payment' => $paypalPayment->id]))->assertOk();
    mfRelay();

    foreach ([$stripePayment, $paypalPayment] as $p) {
        expect($p->fresh()->status)->toBe(PaymentStatus::Failed);
        mfAssertNothingSettled($p);
    }
});

it('PayPal: a capture that is not COMPLETED fails the payment and activates nothing', function () {
    [$company] = mfReferredBuyer();
    $plan = mfPlan(['price_amount' => '29.99', 'price_currency' => 'USD', 'segment' => 'export']);
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::PayPal,
        'amount' => '29.99', 'currency' => 'USD', 'status' => PaymentStatus::Pending, 'provider_reference' => 'ORDER-DECL',
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
        '*/v2/checkout/orders/ORDER-DECL/capture' => Http::response(['name' => 'INSTRUMENT_DECLINED'], 422),
    ]);

    $this->get(URL::temporarySignedRoute('payments.paypal.return', now()->addHour(), ['payment' => $payment->id]).'&token=ORDER-DECL')->assertStatus(400);
    mfRelay();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Failed);
    mfAssertNothingSettled($payment);
});

it('markFailed only moves a payment out of pending', function (PaymentStatus $from) {
    $payment = Payment::factory()->create(['status' => $from]);

    $payment->markFailed();

    expect($payment->fresh()->status)->toBe($from);
})->with([
    'completed' => PaymentStatus::Completed,
    'refunded' => PaymentStatus::Refunded,
    'cancelled' => PaymentStatus::Cancelled,
]);

it('a replayed success confirmation cannot resurrect a refunded or cancelled payment', function (PaymentStatus $terminal) {
    [$company] = mfReferredBuyer();
    $plan = mfPlan(['price_amount' => '19.99', 'price_currency' => 'USD', 'segment' => null]);
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::Stripe,
        'amount' => '19.99', 'currency' => 'USD', 'provider_reference' => 'cs_term', 'status' => $terminal,
    ]);

    mfPostStripe([
        'id' => 'evt_term',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => 'cs_term', 'object' => 'checkout.session', 'payment_intent' => 'pi_term',
            'payment_status' => 'paid', 'amount_total' => 1999, 'currency' => 'usd',
            'metadata' => ['payment_id' => (string) $payment->id],
        ]],
    ])->assertOk();
    mfRelay();

    expect($payment->fresh()->status)->toBe($terminal);
    mfAssertNothingSettled($payment);
})->with([
    'refunded' => PaymentStatus::Refunded,
    'cancelled' => PaymentStatus::Cancelled,
]);

it('a provider confirming success after we gave up on the attempt (failed) still settles it — the money moved', function () {
    [$company, , $referrer] = mfReferredBuyer();
    $plan = mfPlan(['price_amount' => '29.99', 'price_currency' => 'USD', 'segment' => 'export']);
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::PayPal,
        'amount' => '29.99', 'currency' => 'USD', 'status' => PaymentStatus::Failed, 'provider_reference' => 'ORDER-LATE',
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS'], 200),
    ]);

    $this->postJson(route('payments.paypal.webhook'), [
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => 'CAPTURE-LATE',
            'amount' => ['value' => '29.99', 'currency_code' => 'USD'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-LATE']],
        ],
    ])->assertOk();
    mfRelay();

    mfAssertSettledOnce($payment, $plan, '29.99', $referrer);
});

/* ================================================== checkout-side money guards */

it('mobile money refuses a plan that is not priced in XAF (no paying a USD plan in francs)', function (string $provider) {
    [$company, $user] = mfReferredBuyer();
    // Enterprise / analyze plans are not self-serve, so the self-serve
    // provider allow-list does not cover them.
    $plan = mfPlan(['slug' => 'mf-exporter-enterprise-'.Str::lower(Str::random(4)), 'price_amount' => 249, 'price_currency' => 'USD', 'segment' => 'export']);

    Http::fake();

    $this->actingAs($user)->post(route('payments.checkout', $plan), [
        'provider' => $provider,
        'msisdn' => '237670000000',
    ])->assertSessionHasErrors('provider');

    expect(Payment::where('company_id', $company->id)->exists())->toBeFalse();
    Http::assertNothingSent();
})->with(['mtn_momo', 'orange_money']);

it('an inactive (retired) plan cannot be checked out', function () {
    [$company, $user] = mfReferredBuyer();
    $plan = mfPlan(['is_active' => false]);

    $this->actingAs($user)->post(route('payments.checkout', $plan), ['provider' => 'mtn_momo'])->assertNotFound();

    expect(Payment::where('company_id', $company->id)->exists())->toBeFalse();
});

it('every provider callback route is reachable without a CSRF token', function (string $name) {
    // The CSRF middleware skips itself under unit tests, so a 419 in
    // production is invisible to a plain HTTP test — assert on the resolved
    // middleware stack instead. Laravel 13's base class is
    // PreventRequestForgery; excluding only its deprecated ValidateCsrfToken
    // subclass does NOT remove it.
    $route = Route::getRoutes()->getByName($name);
    $csrf = collect(app('router')->gatherRouteMiddleware($route))
        ->filter(fn ($m) => is_string($m) && class_exists($m) && is_a($m, \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, true));

    expect($route->methods())->toContain('POST')
        ->and($csrf->all())->toBe([]);
})->with([
    'payments.mtn-momo.webhook',
    'payments.orange-money.notify',
    'payments.stripe.webhook',
    'payments.paypal.webhook',
]);

/* ================================================================ 3. orders */

/** Supplier on a sell-segment plan. */
function mfSupplier(string $country = 'CM'): Company
{
    $plan = mfPlan(['segment' => 'sell', 'slug' => 'mf-supplier-'.Str::lower(Str::random(6))]);

    return Company::factory()->publiclyVisible()->create(['country_code' => $country, 'plan_id' => $plan->id]);
}

/**
 * Accept a quote with the given lines; returns the minted order.
 *
 * @param  list<array{0: string|float, 1: string|float}>  $lines  [quantity, unit_price]
 */
function mfOrderFromQuote(Company $supplier, string $currency, array $lines, string $shipping = '0', string $tax = '0', string $buyerCountry = 'CM'): Order
{
    $rfq = Rfq::factory()->approved()->create(['buyer_country_code' => $buyerCountry, 'destination_country_code' => $buyerCountry]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 10, 'unit' => 'm3']);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'status' => 'sent', 'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $supplier->getKey(), 'rfq_company_id' => $routing->getKey(),
        'currency' => $currency, 'shipping_amount' => $shipping, 'tax_amount' => $tax,
    ]);

    foreach ($lines as [$qty, $price]) {
        QuoteItem::factory()->create([
            'quote_id' => $quote->getKey(), 'description' => 'Sawn timber',
            'quantity' => $qty, 'unit_price' => $price, 'line_total' => '0',
        ]);
    }

    app(QuoteService::class)->recalculate($quote);
    app(QuoteService::class)->accept($quote->fresh());

    return Order::where('quote_id', $quote->getKey())->with('items')->firstOrFail();
}

function mfCommissionRule(array $overrides = []): CommissionRule
{
    return CommissionRule::create(array_merge([
        'name' => 'MF rule', 'segment' => 'sell', 'plan_tier' => null,
        'domestic_rate' => '0.0300', 'international_rate' => '0.0500',
        'cap_amount' => null, 'cap_percent' => null, 'is_active' => true,
        'effective_from' => null, 'effective_until' => null,
    ], $overrides));
}

it('quote accept -> order totals equal the quote line items (bcmath) plus shipping and tax', function (string $currency, array $lines, string $shipping, string $tax, string $expectedSubtotal) {
    $order = mfOrderFromQuote(mfSupplier(), $currency, $lines, $shipping, $tax);
    $quote = $order->quote()->with('items')->first();

    $quoteLines = $quote->items->reduce(fn ($c, $i) => bcadd($c, (string) $i->line_total, 2), '0.00');
    $orderLines = $order->items->reduce(fn ($c, $i) => bcadd($c, (string) $i->line_total, 2), '0.00');

    expect((string) $order->subtotal_amount)->toBe($expectedSubtotal)
        ->and($orderLines)->toBe($quoteLines)
        ->and((string) $order->subtotal_amount)->toBe((string) $quote->subtotal_amount)
        ->and((string) $order->shipping_amount)->toBe((string) $quote->shipping_amount)
        ->and((string) $order->tax_amount)->toBe((string) $quote->tax_amount)
        ->and((string) $order->total_amount)->toBe(bcadd(bcadd($expectedSubtotal, $shipping, 2), $tax, 2))
        ->and((string) $order->total_amount)->toBe((string) $quote->total_amount)
        ->and($order->currency->value)->toBe($currency);

    foreach ($order->items as $item) {
        expect((string) $item->line_total)->toBe(Quote::lineTotal($item->quantity, $item->unit_price));
    }

    // The order's receipt is for the order total.
    expect((string) $order->receipts()->first()->amount)->toBe((string) $order->total_amount);
})->with([
    'XAF whole francs' => ['XAF', [[12, 185000], [3, 92500]], '250000', '0', '2497500.00'],
    // 3.33 x 10.01 = 33.3333 -> 33.33 ; 2.5 x 10.01 = 25.025 -> 25.03 (half-up)
    'USD cents, half-up per line' => ['USD', [['3.33', '10.01'], ['2.5', '10.01']], '120.50', '15.25', '58.36'],
]);

it('trade-assured order: commission is charged once at the rule rate, rounded to whole francs for XAF', function () {
    $rule = mfCommissionRule();
    $supplier = mfSupplier();
    // 3% of 1,234,567 = 37,037.01 -> 37,037 XAF
    $order = mfOrderFromQuote($supplier, 'XAF', [[1, 1234567]]);

    TradeAssuranceAgreement::createDefaultMilestones($order);
    $order->refresh();

    expect($order->is_commission_charged)->toBeTrue()
        ->and($order->commission_rule_id)->toBe($rule->id)
        ->and((string) $order->commission_amount)->toBe('37037.00');

    // Charging again (re-created agreement / retry) never double-charges,
    // and a later rule edit cannot rewrite the snapshot.
    mfCommissionRule(['name' => 'Newer, higher', 'domestic_rate' => '0.1000', 'effective_from' => now()->toDateString()]);
    app(CommissionCalculator::class)->charge($order->fresh());
    TradeAssuranceAgreement::where('order_id', $order->id)->delete();
    TradeAssuranceAgreement::createDefaultMilestones($order->fresh());

    expect((string) $order->fresh()->commission_amount)->toBe('37037.00');
});

it('trade-assured USD international order: commission uses the international rate at 2dp', function () {
    mfCommissionRule();
    $supplier = mfSupplier('CM');
    // 5% of 10,000.10 = 500.005 -> 500.01
    $order = mfOrderFromQuote($supplier, 'USD', [[1, '10000.10']], '0', '0', 'FR');

    TradeAssuranceAgreement::createDefaultMilestones($order);

    expect((string) $order->fresh()->commission_amount)->toBe('500.01');
});

it('no commission when no rule exists, or when the order is not trade-assured', function () {
    $supplier = mfSupplier();
    $protected = mfOrderFromQuote($supplier, 'XAF', [[10, 100000]]);
    TradeAssuranceAgreement::createDefaultMilestones($protected);

    mfCommissionRule();
    $unprotected = mfOrderFromQuote($supplier, 'XAF', [[10, 100000]]);
    app(CommissionCalculator::class)->charge($unprotected);

    foreach ([$protected->fresh(), $unprotected->fresh()] as $order) {
        expect($order->is_commission_charged)->toBeFalse()
            ->and((float) $order->commission_amount)->toBe(0.0);
    }
});

it('dispute resolved with a refund: commission credit is bounded by what was charged', function () {
    mfCommissionRule();
    $supplier = mfSupplier();
    $order = mfOrderFromQuote($supplier, 'XAF', [[10, 100000]]); // 1,000,000 -> 30,000 commission
    TradeAssuranceAgreement::createDefaultMilestones($order);

    $orders = app(OrderService::class);
    $orders->confirm($order->fresh());
    $orders->ship($order->fresh());

    $buyer = User::factory()->create();
    $order->fresh()->forceFill(['user_id' => $buyer->id])->save();
    $dispute = app(\App\Services\DisputeService::class)->open($order->fresh(), $buyer, \App\Enums\DisputeCategory::cases()[0], 'Short delivery');
    $admin = User::factory()->create();
    $dispute->resolve($admin, 'Partial refund of 40% agreed');

    $calc = app(CommissionCalculator::class);
    $calc->credit($order->fresh(), '12000', 'Dispute #'.$dispute->id.' partial refund');

    expect(fn () => $calc->credit($order->fresh(), '18000.01', 'too much'))->toThrow(RuntimeException::class)
        ->and(fn () => $calc->credit($order->fresh(), '0', 'zero'))->toThrow(RuntimeException::class)
        ->and(fn () => $calc->credit($order->fresh(), '-5', 'negative'))->toThrow(RuntimeException::class);

    $calc->credit($order->fresh(), '18000', 'rest');
    expect(fn () => $calc->credit($order->fresh(), '1', 'nothing left'))->toThrow(RuntimeException::class);

    $order->refresh();
    expect((string) $order->commission_amount)->toBe('30000.00')
        ->and((string) $order->commission_credited_amount)->toBe('30000.00')
        ->and(bccomp((string) $order->commission_credited_amount, (string) $order->commission_amount, 2) <= 0)->toBeTrue();
});

it('cancelling an order before shipping leaves no net commission', function (string $stage) {
    mfCommissionRule();
    $order = mfOrderFromQuote(mfSupplier(), 'XAF', [[10, 100000]]);
    TradeAssuranceAgreement::createDefaultMilestones($order);
    expect($order->fresh()->is_commission_charged)->toBeTrue();

    $orders = app(OrderService::class);
    if (in_array($stage, ['confirmed', 'in_production'], true)) {
        $orders->confirm($order->fresh());
    }
    if ($stage === 'in_production') {
        $orders->startProduction($order->fresh());
    }

    $orders->cancel($order->fresh(), 'Buyer withdrew');
    $order->refresh();

    $net = bcsub((string) $order->commission_amount, (string) ($order->commission_credited_amount ?? '0'), 2);
    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($net)->toBe('0.00');
})->with(['awarded', 'confirmed', 'in_production']);

it('cancelling an order that was never charged commission records no commission at all', function () {
    $order = mfOrderFromQuote(mfSupplier(), 'XAF', [[10, 100000]]);
    TradeAssuranceAgreement::createDefaultMilestones($order); // no rule -> no charge
    mfCommissionRule(); // a rule appearing later must not matter

    app(OrderService::class)->cancel($order->fresh(), 'Buyer withdrew');

    expect($order->fresh()->is_commission_charged)->toBeFalse()
        ->and((float) $order->fresh()->commission_credited_amount)->toBe(0.0);
});

it('a shipped (or later) order cannot be cancelled', function (string $stage) {
    $order = mfOrderFromQuote(mfSupplier(), 'XAF', [[10, 100000]]);
    $orders = app(OrderService::class);
    $orders->confirm($order->fresh());
    $orders->ship($order->fresh());
    if (in_array($stage, ['delivered', 'completed'], true)) {
        $orders->deliver($order->fresh());
    }
    if ($stage === 'completed') {
        $orders->complete($order->fresh());
    }

    expect(fn () => $orders->cancel($order->fresh(), 'Too late'))->toThrow(RuntimeException::class);
    expect($order->fresh()->status->value)->toBe($stage);
})->with(['shipped', 'delivered', 'completed']);
