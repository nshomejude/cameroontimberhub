<?php

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Bus\CommandBus;
use App\Domain\Commerce\Commands\RecordPaymentCompletionCommand;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    (new PlanSeeder)->run();
});

function xafPlan(): Plan
{
    return Plan::where('slug', 'professional')->first(); // sell, 50000 XAF, self-serve
}

function usdPlan(): Plan
{
    return Plan::where('slug', 'exporter-professional')->first(); // export, $29, self-serve
}

function memberOf(Company $company): User
{
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => 'owner']);

    return $user;
}

function configureAllGateways(): void
{
    config([
        'payments.mtn_momo.subscription_key' => 'k', 'payments.mtn_momo.api_user' => 'u', 'payments.mtn_momo.api_key' => 'a',
        'payments.orange_money.client_id' => 'c', 'payments.orange_money.client_secret' => 's', 'payments.orange_money.merchant_key' => 'm',
        'payments.paypal.client_id' => 'c', 'payments.paypal.client_secret' => 's', 'payments.paypal.webhook_id' => 'w',
    ]);
}

it('renders self-serve CTAs on /pricing and contact-sales for enterprise', function () {
    $this->get(route('pricing'))
        ->assertOk()
        ->assertSee('Choose Professional')
        ->assertSee('Contact sales'); // the sell "Enterprise" tier is not self-serve
});

it('redirects a guest to login from the checkout picker', function () {
    $this->get(route('billing.checkout', xafPlan()))->assertRedirect(route('login'));
});

it('tells a logged-in user with no company that a company profile is required', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('billing.checkout', xafPlan()))
        ->assertOk()
        ->assertSee('company profile is required');
});

it('shows MoMo and Orange for an XAF plan and PayPal for a USD plan', function () {
    configureAllGateways();
    $company = Company::factory()->create();
    $user = memberOf($company);

    $this->actingAs($user)->get(route('billing.checkout', xafPlan()))
        ->assertOk()->assertSee('MTN Mobile Money')->assertSee('Orange Money')->assertDontSee('PayPal');

    $this->actingAs($user)->get(route('billing.checkout', usdPlan()))
        ->assertOk()->assertSee('PayPal')->assertDontSee('Orange Money');
});

it('shows the not-available message when no gateway for the plan is configured', function () {
    config(['payments.mtn_momo.subscription_key' => null, 'payments.mtn_momo.api_user' => null, 'payments.mtn_momo.api_key' => null,
        'payments.orange_money.client_id' => null, 'payments.orange_money.client_secret' => null, 'payments.orange_money.merchant_key' => null]);
    $company = Company::factory()->create();

    $this->actingAs(memberOf($company))->get(route('billing.checkout', xafPlan()))
        ->assertOk()->assertSee("isn't available yet");
});

it('creates a pending payment and redirects to the pending page for a MoMo checkout', function () {
    configureAllGateways();
    Http::fake([
        '*/collection/token/' => Http::response(['access_token' => 't'], 200),
        '*/collection/v1_0/requesttopay' => Http::response('', 202),
    ]);
    $company = Company::factory()->create();
    $plan = xafPlan();

    $response = $this->actingAs(memberOf($company))->post(route('payments.checkout', $plan), [
        'provider' => PaymentProvider::MtnMomo->value,
        'msisdn' => '677123456',
    ]);

    $payment = Payment::where('company_id', $company->id)->first();
    expect($payment)->not->toBeNull()
        ->and($payment->plan_id)->toBe($plan->id)
        ->and((float) $payment->amount)->toBe(50000.0)
        ->and($payment->currency)->toBe('XAF')
        ->and($payment->provider)->toBe(PaymentProvider::MtnMomo)
        ->and($payment->status)->toBe(PaymentStatus::Pending);

    $response->assertRedirect(route('billing.checkout.pending', $payment));
});

it('rejects a currency-mismatched provider', function () {
    configureAllGateways();
    $company = Company::factory()->create();

    $this->actingAs(memberOf($company))->post(route('payments.checkout', usdPlan()), [
        'provider' => PaymentProvider::MtnMomo->value,
        'msisdn' => '677123456',
    ])->assertSessionHasErrors('provider');

    expect(Payment::count())->toBe(0);
});

it('exposes payment status as JSON and 403s another company', function () {
    $company = Company::factory()->create();
    $payment = Payment::factory()->create(['company_id' => $company->id, 'plan_id' => xafPlan()->id, 'status' => PaymentStatus::Pending]);

    $this->actingAs(memberOf($company))->getJson(route('billing.checkout.status', $payment))
        ->assertOk()->assertJson(['status' => 'pending']);

    $payment->update(['status' => PaymentStatus::Completed, 'paid_at' => now()]);
    $this->actingAs(memberOf($company))->getJson(route('billing.checkout.status', $payment))
        ->assertOk()->assertJson(['status' => 'completed']);

    $other = Company::factory()->create();
    $this->actingAs(memberOf($other))->getJson(route('billing.checkout.status', $payment))->assertForbidden();
});

it('success page 403s another company and shows the plan + receipt once completed', function () {
    $company = Company::factory()->create();
    $plan = xafPlan();
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'amount' => 50000, 'currency' => 'XAF',
        'status' => PaymentStatus::Pending, 'provider' => PaymentProvider::MtnMomo, 'provider_reference' => 'ref-1',
    ]);

    $other = Company::factory()->create();
    $this->actingAs(memberOf($other))->get(route('billing.checkout.success', $payment))->assertForbidden();

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), 'ref-1'));
    app(RelayOutboxEventsJob::class)->handle();

    $this->actingAs(memberOf($company))->get(route('billing.checkout.success', $payment))
        ->assertOk()->assertSee($plan->name)->assertSee('RCT-');
});

it('issues exactly one receipt per payment, chain intact, idempotent on replay', function () {
    $company = Company::factory()->create();
    $plan = xafPlan();
    $payment = Payment::factory()->create([
        'company_id' => $company->id, 'plan_id' => $plan->id, 'amount' => 50000, 'currency' => 'XAF',
        'status' => PaymentStatus::Pending, 'provider' => PaymentProvider::MtnMomo, 'provider_reference' => 'ref-9',
    ]);

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), 'ref-9'));
    app(RelayOutboxEventsJob::class)->handle();
    app(RelayOutboxEventsJob::class)->handle(); // replay

    $receipts = Receipt::where('payment_id', $payment->id)->get();
    expect($receipts)->toHaveCount(1);
    expect($receipts->first()->verifiesIntegrity())->toBeTrue();

    $this->artisan('receipts:verify-chain')->assertExitCode(0);
});
