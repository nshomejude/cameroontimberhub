<?php

use App\Domain\Commerce\Commands\RecordPaymentCompletionCommand;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Filament\Pages\CommissionReport;
use App\Filament\Resources\PaymentSettings\Pages\ListPaymentSettings;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentSetting;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\ProviderFeeCalculator;
use App\Services\Payments\ProviderFees;
use App\Support\Bus\CommandBus;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    (new PlanSeeder)->run();
    config([
        'payments.paypal.fee_percent' => 4.4, 'payments.paypal.fee_fixed' => 0.30, 'payments.paypal.fee_bearer' => 'buyer',
        'payments.stripe.fee_percent' => 3.4, 'payments.stripe.fee_fixed' => 0.30, 'payments.stripe.fee_bearer' => 'platform',
        'payments.mtn_momo.fee_percent' => 0, 'payments.mtn_momo.fee_fixed' => 0, 'payments.mtn_momo.fee_bearer' => 'platform',
    ]);
});

function feeTestUsdPlan(): Plan
{
    return Plan::where('slug', 'exporter-professional')->firstOrFail(); // export, $29, self-serve
}

function feeTestMember(Company $company): User
{
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => 'owner']);

    return $user;
}

function feeTestConfigurePayPal(): void
{
    config([
        'payments.paypal.environment' => 'sandbox',
        'payments.paypal.client_id' => 'cid', 'payments.paypal.client_secret' => 'secret',
        'payments.paypal.webhook_id' => 'wh', 'payments.paypal.currency' => 'USD',
    ]);
}

/* ------------------------------------------------------------ pure math */

it('grosses a buyer-borne fee up so the platform nets the list price', function () {
    $r = (new ProviderFeeCalculator)->calculate('29.00', 'USD', '4.4', '0.30', 'buyer');

    // (29 + 0.30) / 0.956 = 30.6485… → rounded UP to 30.65
    expect($r['base'])->toBe('29.00')
        ->and($r['total'])->toBe('30.65')
        ->and($r['fee'])->toBe('1.65')
        ->and($r['passed_through'])->toBeTrue()
        ->and($r['percent'])->toBe('4.4');

    // PayPal takes 4.4% + 0.30 of the total — the platform still nets >= 29.00.
    $net = bcsub(bcsub($r['total'], bcmul($r['total'], '0.044', 4), 4), '0.30', 4);
    expect(bccomp($net, '29.00', 4))->toBeGreaterThanOrEqual(0);
});

it('rounds a buyer-borne fee up to whole francs for XAF', function () {
    $r = (new ProviderFeeCalculator)->calculate('50000', 'XAF', '4.4', '0', 'buyer');

    // 50000 / 0.956 = 52301.25… → 52302
    expect($r['total'])->toBe('52302.00')->and($r['fee'])->toBe('2302.00');
});

it('records a platform-borne fee as a cost without changing the charge', function () {
    $r = (new ProviderFeeCalculator)->calculate('29.00', 'USD', '3.4', '0.30', 'platform');

    // 29 × 3.4% + 0.30 = 1.286 → 1.29
    expect($r['total'])->toBe('29.00')
        ->and($r['fee'])->toBe('1.29')
        ->and($r['passed_through'])->toBeFalse();
});

it('charges no fee when none is configured or the price is zero', function () {
    $calc = new ProviderFeeCalculator;

    expect($calc->calculate('50000', 'XAF', '0', '0', 'buyer'))
        ->toMatchArray(['total' => '50000.00', 'fee' => '0.00', 'passed_through' => false]);
    expect($calc->calculate('0', 'USD', '4.4', '0.30', 'buyer'))
        ->toMatchArray(['total' => '0.00', 'fee' => '0.00']);
});

it('rejects an unknown bearer or an impossible percentage', function () {
    expect(fn () => (new ProviderFeeCalculator)->calculate('10', 'USD', '4.4', '0.30', 'nobody'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => (new ProviderFeeCalculator)->calculate('10', 'USD', '100', '0', 'buyer'))
        ->toThrow(InvalidArgumentException::class);
});

/* --------------------------------------------------- settings resolution */

it('lets a PaymentSetting fee override win over the env defaults, column by column', function () {
    expect(ProviderFees::for(PaymentProvider::PayPal))
        ->toMatchArray(['percent' => '4.4', 'fixed' => '0.3', 'bearer' => 'buyer', 'currency' => 'USD']);

    PaymentSetting::forProvider(PaymentProvider::PayPal)->update(['fee_percent' => 3.49, 'fee_bearer' => 'platform']);

    expect(ProviderFees::for(PaymentProvider::PayPal))
        ->toMatchArray(['percent' => '3.490', 'fixed' => '0.3', 'bearer' => 'platform']);
});

it('lets a payments.manage admin edit provider fees from the payment settings table', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(staff('finance_officer'));
    $setting = PaymentSetting::forProvider(PaymentProvider::PayPal);

    Livewire::test(ListPaymentSettings::class)
        ->callTableAction('editFees', $setting, data: ['fee_percent' => '3.9', 'fee_fixed' => '0.49', 'fee_bearer' => 'buyer'])
        ->assertHasNoTableActionErrors();

    expect(ProviderFees::for(PaymentProvider::PayPal))->toMatchArray(['percent' => '3.900', 'fixed' => '0.49', 'bearer' => 'buyer']);
});

/* ------------------------------------------------------------- checkout */

it('creates a PayPal payment for the fee-inclusive total and sends that total to PayPal', function () {
    feeTestConfigurePayPal();
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 't'], 200),
        '*/v2/checkout/orders' => Http::response([
            'id' => 'ORDER-FEE-1',
            'links' => [['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-FEE-1', 'rel' => 'approve']],
        ], 201),
    ]);

    $company = Company::factory()->create();
    $this->actingAs(feeTestMember($company))
        ->post(route('payments.checkout', feeTestUsdPlan()), ['provider' => 'paypal'])
        ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-FEE-1');

    $payment = Payment::where('company_id', $company->id)->firstOrFail();
    expect((string) $payment->amount)->toBe('30.65')
        ->and((string) $payment->base_amount)->toBe('29.00')
        ->and((string) $payment->provider_fee_amount)->toBe('1.65')
        ->and($payment->provider_fee_bearer)->toBe('buyer')
        ->and($payment->baseAmount())->toBe('29.00')
        ->and($payment->subtotalAmount())->toBe('29.00')
        ->and($payment->providerFeePassedThrough())->toBeTrue();

    Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['purchase_units'][0]['amount']['value'] === '30.65');
});

it('completes the PayPal capture only for the fee-inclusive total', function () {
    feeTestConfigurePayPal();
    $company = Company::factory()->create();
    $plan = feeTestUsdPlan();

    $make = fn (string $ref) => Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::PayPal,
        'provider_reference' => $ref, 'currency' => 'USD',
        'amount' => '30.65', 'base_amount' => '29.00', 'provider_fee_amount' => '1.65', 'provider_fee_bearer' => 'buyer',
    ]);
    $capture = fn (string $ref, string $value) => Http::response([
        'id' => $ref, 'status' => 'COMPLETED',
        'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP-'.$ref, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => $value]]]]]],
    ], 200);

    $short = $make('ORDER-SHORT');
    $full = $make('ORDER-FULL');

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 't'], 200),
        '*/v2/checkout/orders/ORDER-SHORT/capture' => $capture('ORDER-SHORT', '29.00'), // base only — must be rejected
        '*/v2/checkout/orders/ORDER-FULL/capture' => $capture('ORDER-FULL', '30.65'),
    ]);

    $this->get(URL::temporarySignedRoute('payments.paypal.return', now()->addDay(), ['payment' => $short->id]).'&token=ORDER-SHORT')
        ->assertStatus(400);
    expect($short->refresh()->status)->toBe(PaymentStatus::Pending);

    $this->get(URL::temporarySignedRoute('payments.paypal.return', now()->addDay(), ['payment' => $full->id]).'&token=ORDER-FULL')
        ->assertOk();
    expect($full->refresh()->status)->toBe(PaymentStatus::Completed);
});

it('keeps mobile money fee-free: amount equals the plan price', function () {
    config(['payments.mtn_momo.subscription_key' => null]); // unconfigured → 503 page, row still created
    $company = Company::factory()->create();

    $this->actingAs(feeTestMember($company))
        ->post(route('payments.checkout', Plan::where('slug', 'professional')->first()), ['provider' => 'mtn_momo']);

    $payment = Payment::where('company_id', $company->id)->firstOrFail();
    expect((string) $payment->amount)->toBe('50000.00')
        ->and((string) $payment->base_amount)->toBe('50000.00')
        ->and((string) $payment->provider_fee_amount)->toBe('0.00')
        ->and($payment->providerFeePassedThrough())->toBeFalse();
});

it('discloses subtotal, PayPal fee and total on the checkout page before payment', function () {
    feeTestConfigurePayPal();
    $user = feeTestMember(Company::factory()->create());

    $this->actingAs($user)->get(route('billing.checkout', feeTestUsdPlan()))
        ->assertOk()
        ->assertSee('data-provider-fee="paypal"', false)
        ->assertSee('PayPal processing fee')
        ->assertSee('1.65 USD')
        ->assertSee('30.65 USD');

    $this->actingAs($user)->get(route('billing.checkout', Plan::where('slug', 'professional')->first()))
        ->assertOk()
        ->assertDontSee('data-provider-fee', false)
        ->assertDontSee('processing fee');
});

it('exposes the checkout breakdown per provider on the API', function () {
    feeTestConfigurePayPal();
    $user = feeTestMember(Company::factory()->create());

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/billing/checkout/exporter-professional')
        ->assertOk()
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.price', '29.00')
        ->assertJsonPath('data.providers.0.provider', 'paypal')
        ->assertJsonPath('data.providers.0.subtotal', '29.00')
        ->assertJsonPath('data.providers.0.provider_fee', '1.65')
        ->assertJsonPath('data.providers.0.provider_fee_passed_through', true)
        ->assertJsonPath('data.providers.0.total', '30.65');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/billing/checkout/professional')
        ->assertOk()
        ->assertJsonPath('data.providers.0.provider_fee', '0.00')
        ->assertJsonPath('data.providers.0.total', '50000.00');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/billing/checkout/enterprise')->assertNotFound();
});

/* ------------------------------------------------- invoice + reporting */

it('bills a passed-through fee as its own invoice line and shows it on the success page', function () {
    $company = Company::factory()->create();
    $plan = feeTestUsdPlan();
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'provider' => PaymentProvider::PayPal,
        'provider_reference' => 'ORDER-INV', 'currency' => 'USD', 'status' => PaymentStatus::Pending,
        'amount' => '30.65', 'base_amount' => '29.00', 'provider_fee_amount' => '1.65', 'provider_fee_bearer' => 'buyer',
    ]);

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), 'CAP-INV'));
    app(RelayOutboxEventsJob::class)->handle();

    $invoice = Invoice::where('payment_id', $payment->id)->firstOrFail();
    expect((string) $invoice->total_amount)->toBe('30.65')
        ->and((string) $invoice->subtotal_amount)->toBe('30.65')
        ->and($invoice->lines()->orderBy('sort')->pluck('line_total')->map(fn ($v) => (string) $v)->all())->toBe(['29.00', '1.65'])
        ->and($invoice->lines()->where('sort', 1)->value('description'))->toBe('PayPal processing fee');

    $this->actingAs(feeTestMember($company))->get(route('billing.checkout.success', $payment))
        ->assertOk()->assertSee('PayPal processing fee')->assertSee('1.65 USD')->assertSee('30.65 USD');
});

it('reports provider fees collected vs absorbed per provider and currency', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    Payment::factory()->create(['plan_id' => feeTestUsdPlan()->id, 'provider' => PaymentProvider::PayPal, 'currency' => 'USD', 'status' => PaymentStatus::Completed,
        'amount' => '30.65', 'base_amount' => '29.00', 'provider_fee_amount' => '1.65', 'provider_fee_bearer' => 'buyer']);
    Payment::factory()->create(['plan_id' => feeTestUsdPlan()->id, 'provider' => PaymentProvider::PayPal, 'currency' => 'USD', 'status' => PaymentStatus::Completed,
        'amount' => '29.00', 'base_amount' => '29.00', 'provider_fee_amount' => '1.58', 'provider_fee_bearer' => 'platform']);
    Payment::factory()->create(['plan_id' => feeTestUsdPlan()->id, 'provider' => PaymentProvider::PayPal, 'currency' => 'USD', 'status' => PaymentStatus::Pending,
        'amount' => '30.65', 'base_amount' => '29.00', 'provider_fee_amount' => '1.65', 'provider_fee_bearer' => 'buyer']);

    $rows = (new CommissionReport)->getProviderFeeRows();

    expect($rows)->toHaveCount(1)
        ->and($rows->first())->toMatchArray([
            'provider' => 'PayPal', 'currency' => 'USD', 'payments' => 2,
            'charged' => '59.65 USD', 'fees_collected' => '1.65 USD', 'fees_absorbed' => '1.58 USD', 'net' => '56.42 USD',
        ]);

    $this->actingAs(staff('super_admin'))->get('/admin/commission-report')->assertOk()->assertSee('Payment provider fees');
});
