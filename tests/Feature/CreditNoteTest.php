<?php

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceIssuer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;

/*
 * Billing engine M4 — credit notes correct an invoice without ever mutating
 * it, on their own hash chain, and can never over-credit the invoice.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('issues a full credit note without changing the invoice', function () {
    $invoice = Invoice::factory()->create(['subtotal_amount' => '100000.00', 'tax_amount' => '0.00', 'total_amount' => '100000.00']);
    $before = $invoice->hash;

    $note = app(InvoiceIssuer::class)->issueCreditNote($invoice, 'duplicate charge', User::factory()->create());

    expect((string) $note->total_amount)->toBe('100000.00')
        ->and($note->fresh()->verifiesIntegrity())->toBeTrue()
        ->and($invoice->fresh()->hash)->toBe($before);

    $this->artisan('invoices:verify-chain')->assertExitCode(0);
});

it('rejects a second credit note that would exceed the invoice total', function () {
    $invoice = Invoice::factory()->create(['subtotal_amount' => '100000.00', 'tax_amount' => '0.00', 'total_amount' => '100000.00']);
    $by = User::factory()->create();

    app(InvoiceIssuer::class)->issueCreditNote($invoice, 'full', $by);

    expect(fn () => app(InvoiceIssuer::class)->issueCreditNote($invoice, 'again', $by))
        ->toThrow(RuntimeException::class);
});

it('rejects a credit note against a void invoice', function () {
    $invoice = Invoice::factory()->create();
    $invoice->void(User::factory()->create(), 'error');

    expect(fn () => app(InvoiceIssuer::class)->issueCreditNote($invoice, 'x', User::factory()->create()))
        ->toThrow(RuntimeException::class);
});

it('hides the Invoices resource without billing.view and shows it with it', function () {
    $this->actingAs(staff('content_manager'));
    $this->get('/admin/invoices')->assertForbidden();

    $this->actingAs(staff('finance_officer'));
    $this->get('/admin/invoices')->assertOk();
    $this->get('/admin/credit-notes')->assertOk();
});

it('exposes no Invoices create page', function () {
    expect(App\Filament\Resources\Invoices\InvoiceResource::canCreate())->toBeFalse()
        ->and(array_key_exists('create', App\Filament\Resources\Invoices\InvoiceResource::getPages()))->toBeFalse()
        ->and(app('router')->getRoutes()->getByName('filament.admin.resources.invoices.create'))->toBeNull();
});

it('voids an invoice through the admin table action', function () {
    $invoice = Invoice::factory()->create();
    $this->actingAs(staff('finance_officer'));

    Livewire\Livewire::test(App\Filament\Resources\Invoices\Pages\ListInvoices::class)
        ->callTableAction('void', $invoice, ['reason' => 'issued in error']);

    expect($invoice->fresh()->isVoid())->toBeTrue();
    $this->artisan('invoices:verify-chain')->assertExitCode(0);
});
