<?php

use App\Enums\PaymentProvider;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Receipt;
use App\Models\User;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    (new PlanSeeder)->run();
});

function billingMember(Company $company): User
{
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

it('shows the no-company state to a buyer with no company', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('billing.overview'))
        ->assertOk()
        ->assertSee("don't have a company subscription")
        ->assertSee(route('pricing'))
        ->assertDontSee('No invoices yet.');
});

it('shows the Free plan state with a change-plan link for a company on no subscription', function () {
    $company = Company::factory()->create();

    $this->actingAs(billingMember($company))
        ->get(route('billing.overview'))
        ->assertOk()
        ->assertSee(__('messages.billing.see_paid_plans'))
        ->assertSee(route('pricing'));
});

it('shows plan name, price, status badge and renew date for an active paid subscription', function () {
    $plan = Plan::where('slug', 'professional')->first();
    $company = Company::factory()->create();
    $company->currentSubscription->update([
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'renews_at' => now()->addMonth(),
    ]);

    $this->actingAs(billingMember($company))
        ->get(route('billing.overview'))
        ->assertOk()
        ->assertSee($plan->name)
        ->assertSee(__('messages.enums.subscription_status.active'))
        ->assertSee('Renews on');
});

it('lists the company invoices newest-first with View and PDF links and hides other companies', function () {
    $company = Company::factory()->create();
    $mine = Invoice::factory()->create(['company_id' => $company->id, 'issued_at' => now()->subDays(2)]);
    $newer = Invoice::factory()->create(['company_id' => $company->id, 'issued_at' => now()]);
    $other = Invoice::factory()->create(['company_id' => Company::factory()->create()->id]);

    $res = $this->actingAs(billingMember($company))->get(route('billing.overview'))->assertOk();

    $res->assertSee($mine->invoice_number)->assertSee($newer->invoice_number)
        ->assertDontSee($other->invoice_number)
        ->assertSee(route('billing.invoices.show', $newer))
        ->assertSee(route('billing.invoices.show', ['invoice' => $newer, 'format' => 'pdf']));

    expect(strpos($res->getContent(), $newer->invoice_number))
        ->toBeLessThan(strpos($res->getContent(), $mine->invoice_number));
});

it('renders receipts and payment history scoped to the company', function () {
    $company = Company::factory()->create();
    $plan = Plan::where('slug', 'professional')->first();

    $payment = Payment::factory()->completed()->create([
        'company_id' => $company->id,
        'plan_id' => $plan->id,
        'provider' => PaymentProvider::MtnMomo,
        'amount' => 50000,
        'currency' => 'XAF',
    ]);
    $receipt = Receipt::factory()->create([
        'order_id' => null,
        'payment_id' => $payment->id,
        'amount' => 50000,
        'currency' => 'XAF',
    ]);

    $otherPayment = Payment::factory()->completed()->create(['company_id' => Company::factory()->create()->id]);

    $this->actingAs(billingMember($company))->get(route('billing.overview'))
        ->assertOk()
        ->assertSee($receipt->receipt_number)
        ->assertSee(__('messages.billing.payment_history'))
        ->assertSee('MTN Mobile Money')
        ->assertSee(__('messages.enums.payment_status.completed'))
        ->assertSee(__('messages.billing.payment_method_note'))
        ->assertDontSee($otherPayment->provider_reference);
});

it('shows the grace message for a past-due subscription still in grace', function () {
    $plan = Plan::where('slug', 'professional')->first();
    $company = Company::factory()->create();
    $company->currentSubscription->update([
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::PastDue,
        'grace_until' => now()->addDays(7),
    ]);

    $this->actingAs(billingMember($company))->get(route('billing.overview'))
        ->assertOk()
        ->assertSee('access continues until');
});
