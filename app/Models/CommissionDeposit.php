<?php

namespace App\Models;

use App\Enums\CommissionDepositMethod;
use App\Enums\CommissionDepositStatus;
use App\Enums\RfqCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual commission payment (MTN MoMo / Orange Money / bank) against a
 * CommissionStatement — reported by the supplier (`source = supplier`,
 * `pending` until finance confirms or rejects it) or recorded directly by
 * finance for money that arrived without a report (`source = admin`, born
 * `confirmed`). See App\Services\Commission\CommissionCollectionService.
 *
 * `reference_key` is the normalised transaction reference (trimmed, inner
 * whitespace removed, upper-cased): the database allows each reference once
 * per method among non-rejected deposits.
 *
 * The optional proof (image / PDF) lives on the private `documents` disk and
 * is only streamed through authorised actions (supplier: own company; admin:
 * `billing.view`).
 */
class CommissionDeposit extends Model
{
    public const SOURCE_SUPPLIER = 'supplier';

    public const SOURCE_ADMIN = 'admin';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'method' => CommissionDepositMethod::class,
            'status' => CommissionDepositStatus::class,
            'currency' => RfqCurrency::class,
            'amount' => 'decimal:2',
            'amount_received' => 'decimal:2',
            'paid_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public static function normaliseReference(string $reference): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', trim($reference)));
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CommissionStatement::class, 'commission_statement_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === CommissionDepositStatus::Pending;
    }

    public function hasProof(): bool
    {
        return filled($this->proof_path) && filled($this->proof_disk);
    }

    public function money(float|string|null $amount = null): string
    {
        return CommissionStatement::format($amount ?? $this->amount, $this->currency->value);
    }
}
