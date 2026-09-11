<?php

namespace App\Services\Billing;

use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\InvoiceNumberGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Issues immutable invoices and credit notes (billing engine M4).
 *
 * - `issueForPayment()` auto-creates a `paid` invoice when a plan Payment
 *   completes. Idempotent by `payment_id`.
 * - `issueCreditNote()` records a correction/refund against an invoice
 *   WITHOUT ever mutating the invoice (plan §2).
 *
 * Every issued document joins its model's ChainsIntegrity hash chain.
 *
 * TODO (billing plan §7.6): a refund/credit-note over 100,000 XAF (~$200)
 * must additionally require a second approver + fresh 2FA (`billing.refund`
 * / `finance_officer`). The refund ACTION itself is Phase 3; this service
 * only provides the data model + the plain admin-issue flow.
 */
class InvoiceIssuer
{
    public function __construct(private readonly InvoiceNumberGenerator $numbers) {}

    public function issueForPayment(Payment $payment): Invoice
    {
        $existing = Invoice::where('payment_id', $payment->getKey())->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($payment): Invoice {
            // Re-check inside the transaction to close the double-relay race.
            $existing = Invoice::where('payment_id', $payment->getKey())->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            $plan = $payment->plan_id ? Plan::find($payment->plan_id) : null;
            $company = Company::findOrFail($payment->company_id);
            $subscription = Subscription::where('payment_id', $payment->getKey())->first();

            $tax = is_array($payment->metadata['tax'] ?? null) ? $payment->metadata['tax'] : null;

            if ($tax !== null) {
                $subtotal = $this->scale2((string) $tax['subtotal']);
                $taxAmount = $this->scale2((string) $tax['tax_amount']);
                $total = $this->scale2((string) $tax['total']);
                $taxRate = $tax['tax_rate'] ?? null;
                $taxLabel = $tax['tax_label'] ?? null;
                $taxRuleId = $tax['rule_id'] ?? null;
            } else {
                $subtotal = $this->scale2((string) $payment->amount);
                $taxAmount = $this->scale2('0');
                $total = $subtotal;
                $taxRate = null;
                $taxLabel = null;
                $taxRuleId = null;
            }

            $invoice = Invoice::create([
                'invoice_number' => $this->numbers->invoice(),
                'company_id' => $company->getKey(),
                'payment_id' => $payment->getKey(),
                'subscription_id' => $subscription?->getKey(),
                'tax_rule_id' => $taxRuleId,
                'status' => \App\Enums\InvoiceStatus::Paid,
                'currency' => strtoupper((string) $payment->currency),
                'subtotal_amount' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $total,
                'tax_rate' => $taxRate,
                'tax_label' => $taxLabel,
                'bill_to' => $this->billTo($company),
                'bill_from' => $this->billFrom(),
                'issued_at' => now(),
            ]);

            $description = $plan !== null
                ? trim($plan->name.' — '.((string) $plan->billing_period).' subscription')
                : 'Subscription charge';

            $invoice->lines()->create([
                'description' => $description,
                'quantity' => 1,
                'unit_amount' => $subtotal,
                'line_total' => $subtotal,
                'sort' => 0,
            ]);

            activity('invoice')
                ->performedOn($invoice)
                ->event('issued')
                ->withProperties([
                    'invoice_number' => $invoice->invoice_number,
                    'payment_id' => $payment->getKey(),
                    'total_amount' => $total,
                    'currency' => $invoice->currency->value,
                ])
                ->log("Invoice {$invoice->invoice_number} issued for payment #{$payment->getKey()}");

            return $invoice;
        });
    }

    /**
     * @param  list<array{description:string,quantity?:int,unit_amount:string|float}>|null  $lines
     */
    public function issueCreditNote(Invoice $invoice, string $reason, User $by, ?array $lines = null): CreditNote
    {
        return DB::transaction(function () use ($invoice, $reason, $by, $lines): CreditNote {
            $invoice = Invoice::whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($invoice->isVoid()) {
                throw new RuntimeException('Cannot issue a credit note against a void invoice.');
            }

            if ($lines === null) {
                $subtotal = $this->scale2((string) $invoice->subtotal_amount);
                $taxAmount = $this->scale2((string) $invoice->tax_amount);
                $total = $this->scale2((string) $invoice->total_amount);
                $rows = [[
                    'description' => 'Full credit of invoice '.$invoice->invoice_number,
                    'quantity' => 1,
                    'unit_amount' => $subtotal,
                    'line_total' => $subtotal,
                    'sort' => 0,
                ]];
            } else {
                $subtotal = '0';
                $rows = [];
                foreach (array_values($lines) as $i => $line) {
                    $qty = (int) ($line['quantity'] ?? 1);
                    $unit = $this->scale2((string) $line['unit_amount']);
                    $lineTotal = $this->scale2(bcmul($unit, (string) $qty, 4));
                    $subtotal = bcadd($subtotal, $lineTotal, 2);
                    $rows[] = [
                        'description' => (string) $line['description'],
                        'quantity' => $qty,
                        'unit_amount' => $unit,
                        'line_total' => $lineTotal,
                        'sort' => $i,
                    ];
                }
                // Partial credits are recorded net of tax; the tax treatment of
                // a partial refund is a Phase 3 concern.
                $taxAmount = $this->scale2('0');
                $total = $this->scale2($subtotal);
            }

            $alreadyCredited = $invoice->creditedTotal();
            if (bccomp(bcadd($alreadyCredited, $total, 2), (string) $invoice->total_amount, 2) === 1) {
                throw new RuntimeException(
                    "Credit notes against invoice {$invoice->invoice_number} would exceed its total "
                    ."({$invoice->currency->value} {$invoice->total_amount})."
                );
            }

            $note = CreditNote::create([
                'credit_note_number' => $this->numbers->creditNote(),
                'invoice_id' => $invoice->getKey(),
                'company_id' => $invoice->company_id,
                'issued_by' => $by->getKey(),
                'status' => \App\Enums\CreditNoteStatus::Issued,
                'currency' => $invoice->currency->value,
                'subtotal_amount' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $total,
                'reason' => $reason,
                'issued_at' => now(),
            ]);

            foreach ($rows as $row) {
                $note->lines()->create($row);
            }

            activity('credit_note')
                ->performedOn($note)
                ->causedBy($by)
                ->event('issued')
                ->withProperties([
                    'credit_note_number' => $note->credit_note_number,
                    'invoice_number' => $invoice->invoice_number,
                    'total_amount' => $total,
                    'reason' => $reason,
                ])
                ->log("Credit note {$note->credit_note_number} issued against invoice {$invoice->invoice_number}");

            return $note;
        });
    }

    /** @return array<string,mixed> */
    private function billTo(Company $company): array
    {
        return [
            'legal_name' => $company->legal_name,
            'trade_name' => $company->trade_name,
            'address_line' => $company->address_line,
            'city' => $company->city,
            'region' => $company->region,
            'country_code' => $company->country_code,
        ];
    }

    /** @return array<string,mixed> */
    private function billFrom(): array
    {
        return [
            'organisation' => config('contact.organisation'),
            'address_lines' => config('contact.address.lines', []),
            'locality' => config('contact.address.locality'),
            'region' => config('contact.address.region'),
            'country' => config('contact.address.country'),
            'email' => config('contact.emails.0'),
            'phone' => config('contact.phones.0'),
            'website' => config('contact.website'),
        ];
    }

    private function scale2(string $n): string
    {
        return bcadd($n, '0', 2);
    }
}
