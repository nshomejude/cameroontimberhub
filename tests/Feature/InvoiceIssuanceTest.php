<?php

use App\Domain\Commerce\Commands\RecordPaymentCompletionCommand;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\TaxRule;
use App\Services\Billing\InvoiceIssuer;
use App\Support\Bus\CommandBus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * Billing engine M4 — a completed plan Payment auto-issues exactly one
 * immutable, hash-chained Invoice.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function completePlanPayment(array $overrides = []): Payment
{
    $company = Company::factory()->create();
    $plan = Plan::factory()->create(['billing_period' => 'monthly', 'price_amount' => 50000, 'price_currency' => 'XAF']);

    $payment = Payment::factory()->create(array_merge([
        'company_id' => $company->id,
        'plan_id' => $plan->id,
        'provider' => PaymentProvider::MtnMomo,
        'status' => PaymentStatus::Pending,
        'amount' => 50000,
        'currency' => 'XAF',
        'provider_reference' => 'ref-'.Str::random(8),
    ], $overrides));

    app(CommandBus::class)->dispatch(new RecordPaymentCompletionCommand($payment->getKey(), $payment->provider_reference));
    app(\App\Jobs\RelayOutboxEventsJob::class)->handle();

    return $payment->fresh();
}

it('issues exactly one paid, chained invoice when a payment completes', function () {
    $payment = completePlanPayment();

    $invoices = Invoice::where('payment_id', $payment->id)->get();

    expect($invoices)->toHaveCount(1);

    $invoice = $invoices->first();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->lines)->toHaveCount(1)
        ->and((float) $invoice->total_amount)->toBe((float) $payment->amount)
        ->and($invoice->bill_to)->not->toBeEmpty()
        ->and($invoice->bill_from)->not->toBeEmpty()
        ->and($invoice->hash)->not->toBeNull()
        ->and($invoice->prev_hash)->toBeNull()
        ->and($invoice->fresh()->verifiesIntegrity())->toBeTrue();
});

it('chains a second invoice to the first', function () {
    $a = Invoice::where('payment_id', completePlanPayment()->id)->first();
    $b = Invoice::where('payment_id', completePlanPayment()->id)->first();

    expect($b->prev_hash)->toBe($a->hash)
        ->and($b->hash)->not->toBe($a->hash);

    $this->artisan('invoices:verify-chain')->assertExitCode(0);
});

it('is idempotent — a double relay yields one invoice row', function () {
    $payment = completePlanPayment();

    app(\App\Jobs\RelayOutboxEventsJob::class)->handle();
    $again = app(InvoiceIssuer::class)->issueForPayment($payment);
    $once = app(InvoiceIssuer::class)->issueForPayment($payment);

    expect(Invoice::where('payment_id', $payment->id)->count())->toBe(1)
        ->and($again->id)->toBe($once->id);
});

it('freezes the tax breakdown on the invoice when a rule was active at checkout', function () {
    $rule = TaxRule::create([
        'name' => 'Cameroon TVA',
        'jurisdiction' => 'CM',
        'rate' => '0.1925',
        'is_active' => true,
    ]);

    $payment = completePlanPayment([
        'amount' => 59625,
        'metadata' => ['tax' => [
            'subtotal' => '50000.00',
            'tax_rate' => '0.1925',
            'tax_label' => 'Cameroon TVA',
            'tax_amount' => '9625.00',
            'total' => '59625.00',
            'rule_id' => $rule->id,
        ]],
    ]);

    $invoice = Invoice::where('payment_id', $payment->id)->first();

    expect((string) $invoice->subtotal_amount)->toBe('50000.00')
        ->and((string) $invoice->tax_amount)->toBe('9625.00')
        ->and((string) $invoice->total_amount)->toBe('59625.00')
        ->and((string) $invoice->tax_rate)->toBe('0.1925')
        ->and($invoice->tax_label)->toBe('Cameroon TVA')
        ->and($invoice->tax_rule_id)->toBe($rule->id);

    // A later rate change must not touch the issued invoice.
    $rule->update(['is_active' => false]);
    TaxRule::create(['name' => 'Cameroon TVA v2', 'jurisdiction' => 'CM', 'rate' => '0.2500', 'is_active' => true]);

    expect((string) $invoice->fresh()->tax_rate)->toBe('0.1925')
        ->and($invoice->fresh()->verifiesIntegrity())->toBeTrue();
});

it('serves the invoice print view to an owning company member and 403s an unrelated user', function () {
    $payment = completePlanPayment();
    $invoice = Invoice::where('payment_id', $payment->id)->first();

    $member = App\Models\User::factory()->create();
    $member->companies()->attach($invoice->company_id, ['role' => 'owner']);

    $this->actingAs($member)->get(route('billing.invoices.show', $invoice))->assertOk()->assertSee($invoice->invoice_number);

    $stranger = App\Models\User::factory()->create();
    $this->actingAs($stranger)->get(route('billing.invoices.show', $invoice))->assertForbidden();
});
