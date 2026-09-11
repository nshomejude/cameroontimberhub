<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\ChainsIntegrity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The platform's immutable, hash-chained invoice for a completed charge
 * (billing engine M4, plan §2 "historical invoices never change").
 *
 * An invoice is auto-issued (born `paid`) when a plan Payment completes
 * (App\Services\Billing\InvoiceIssuer). Its issued facts — number, party,
 * amounts, currency, date — are frozen by ChainsIntegrity and can never be
 * edited afterwards. A correction is a separate CreditNote against this
 * invoice; the invoice row itself is only ever transitioned to `void`
 * (mutable operational state, deliberately outside the hash payload, exactly
 * as Receipt keeps `voided_at` out).
 */
class Invoice extends Model
{
    use ChainsIntegrity;
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * The issued facts the integrity hash attests to. `status` and `notes`
     * are deliberately excluded so an invoice can be voided (or annotated)
     * without breaking the chain — mirrors Receipt keeping `voided_at` out.
     * `tax_rate` / `tax_label` / `tax_rule_id` are frozen snapshots too but
     * are informational; the amounts they produced are what the hash covers.
     *
     * @return list<string>
     */
    public function integrityPayloadColumns(): array
    {
        return ['invoice_number', 'company_id', 'payment_id', 'issued_at', 'subtotal_amount', 'tax_amount', 'total_amount', 'currency'];
    }

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'currency' => \App\Enums\RfqCurrency::class,
            'subtotal_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'bill_to' => 'array',
            'bill_from' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort')->orderBy('id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function taxRule(): BelongsTo
    {
        return $this->belongsTo(TaxRule::class, 'tax_rule_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function isVoid(): bool
    {
        return $this->status === InvoiceStatus::Void;
    }

    /** Total already credited against this invoice by non-void credit notes. */
    public function creditedTotal(): string
    {
        $sum = '0';
        foreach ($this->creditNotes()->where('status', \App\Enums\CreditNoteStatus::Issued->value)->get() as $note) {
            $sum = bcadd($sum, (string) $note->total_amount, 2);
        }

        return $sum;
    }

    public function money(float|string|null $amount = null): string
    {
        return $this->currency->value.' '.number_format((float) ($amount ?? $this->total_amount), 2);
    }

    /**
     * Void this invoice (issued in error). Flips operational status only —
     * never touches a payload column, so `invoices:verify-chain` stays green.
     */
    public function void(User $by, string $reason): void
    {
        $this->update(['status' => InvoiceStatus::Void, 'notes' => $reason]);

        activity('invoice')
            ->performedOn($this)
            ->causedBy($by)
            ->event('voided')
            ->withProperties(['invoice_number' => $this->invoice_number, 'reason' => $reason])
            ->log("Invoice {$this->invoice_number} voided");
    }
}
