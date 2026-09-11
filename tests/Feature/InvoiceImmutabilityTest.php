<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Billing engine M4 — an issued invoice's payload columns are frozen; only
 * operational state (void) may change, and doing so keeps the chain green.
 */

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('throws when a payload column of an issued invoice is edited', function () {
    $invoice = Invoice::factory()->create();

    try {
        $invoice->update(['total_amount' => 1]);
        $this->fail('Expected a RuntimeException mutating a payload column.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('immutable');
    }
});

it('allows void() and keeps invoices:verify-chain green', function () {
    Invoice::factory()->count(2)->create();
    $invoice = Invoice::factory()->create();

    $this->artisan('invoices:verify-chain')->assertExitCode(0);

    $invoice->void(User::factory()->create(), 'issued in error');

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Void)
        ->and($invoice->fresh()->verifiesIntegrity())->toBeTrue();

    $this->artisan('invoices:verify-chain')->assertExitCode(0);
});

it('invoices:verify-chain fails after a raw tamper', function () {
    $invoices = Invoice::factory()->count(3)->create();

    $this->artisan('invoices:verify-chain')->assertExitCode(0);

    DB::table('invoices')->where('id', $invoices[1]->id)->update(['total_amount' => 999999]);

    $this->artisan('invoices:verify-chain')->assertExitCode(1);
});
