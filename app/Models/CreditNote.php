<?php

namespace App\Models;

use App\Enums\CreditNoteStatus;
use App\Models\Concerns\ChainsIntegrity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A credit note issued against an Invoice (billing engine M4, plan §2).
 *
 * Corrections and refunds never edit the original invoice — a credit note
 * records the amount credited back, on its own hash chain (ChainsIntegrity,
 * separate from the invoice chain). The sum of non-void credit notes against
 * an invoice can never exceed that invoice's total (enforced in
 * App\Services\Billing\InvoiceIssuer).
 */
class CreditNote extends Model
{
    use ChainsIntegrity;
    use HasFactory;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function integrityPayloadColumns(): array
    {
        return ['credit_note_number', 'invoice_id', 'company_id', 'issued_at', 'subtotal_amount', 'tax_amount', 'total_amount', 'currency'];
    }

    protected function casts(): array
    {
        return [
            'status' => CreditNoteStatus::class,
            'currency' => \App\Enums\RfqCurrency::class,
            'subtotal_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class)->orderBy('sort')->orderBy('id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function money(float|string|null $amount = null): string
    {
        return $this->currency->value.' '.number_format((float) ($amount ?? $this->total_amount), 2);
    }
}
