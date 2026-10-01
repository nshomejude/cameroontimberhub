<?php

namespace App\Models;

use App\Enums\CommissionDepositStatus;
use App\Enums\CommissionStatementStatus;
use App\Enums\RfqCurrency;
use App\Models\Concerns\ChainsIntegrity;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monthly marketplace-commission statement for one supplier company in one
 * currency (owner decision 2026-10-01: commission is collected by manual
 * MoMo / bank deposit against these statements, never auto-debited).
 *
 * Issued by App\Services\Commission\CommissionStatementIssuer; paid down by
 * confirmed App\Models\CommissionDeposit rows through
 * App\Services\Commission\CommissionCollectionService, the only writer of
 * `amount_paid` / `status`.
 *
 * Hash-chained exactly like App\Models\Invoice (ChainsIntegrity): the issued
 * facts — number, company, period, currency, amounts, issue and due date —
 * can never change after issue. Payment state, reminders and the void
 * columns are operational state outside the hash.
 */
class CommissionStatement extends Model
{
    use ChainsIntegrity;

    protected $guarded = ['id'];

    /** @return list<string> */
    public function integrityPayloadColumns(): array
    {
        return [
            'statement_number', 'company_id', 'period_start', 'currency',
            'charges_amount', 'adjustments_amount', 'total_amount', 'issued_at', 'due_date',
        ];
    }

    protected function casts(): array
    {
        return [
            'status' => CommissionStatementStatus::class,
            'currency' => RfqCurrency::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'due_date' => 'date',
            'charges_amount' => 'decimal:2',
            'adjustments_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'due_reminder_sent_at' => 'datetime',
            'overdue_notified_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CommissionStatementLine::class)->orderBy('kind')->orderBy('id');
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(CommissionDeposit::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** Statements still owed (issued / partially paid / overdue). */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', CommissionStatementStatus::openValues());
    }

    public function scopeForCompany(Builder $query, Company|int $company): Builder
    {
        return $query->where('company_id', $company instanceof Company ? $company->getKey() : $company);
    }

    public function isVoid(): bool
    {
        return $this->status === CommissionStatementStatus::Void;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /** Total minus confirmed payments, never negative, as a 2dp string. */
    public function outstanding(): string
    {
        if ($this->isVoid()) {
            return '0.00';
        }

        $due = bcsub((string) $this->total_amount, (string) $this->amount_paid, 2);

        return bccomp($due, '0', 2) > 0 ? $due : '0.00';
    }

    /** Sum of deposits still awaiting finance review. */
    public function pendingDepositsTotal(): string
    {
        return $this->deposits()
            ->where('status', CommissionDepositStatus::Pending->value)
            ->get(['amount'])
            ->reduce(fn (string $c, CommissionDeposit $d) => bcadd($c, (string) $d->amount, 2), '0.00');
    }

    /** Past due date and still owed (independent of whether the daily job has flipped the status yet). */
    public function isPastDue(): bool
    {
        return $this->isOpen() && $this->due_date->lt(today());
    }

    /**
     * The status the payment state implies (never `void`, which only
     * CommissionCollectionService::void() sets).
     */
    public function resolvedStatus(): CommissionStatementStatus
    {
        if ($this->isVoid()) {
            return CommissionStatementStatus::Void;
        }

        if (bccomp((string) $this->amount_paid, (string) $this->total_amount, 2) >= 0) {
            return CommissionStatementStatus::Paid;
        }

        if ($this->due_date->lt(today())) {
            return CommissionStatementStatus::Overdue;
        }

        return bccomp((string) $this->amount_paid, '0', 2) > 0
            ? CommissionStatementStatus::PartiallyPaid
            : CommissionStatementStatus::Issued;
    }

    /** "XAF 12,500" / "USD 310.25" — currency code up front, real precision. */
    public function money(float|string|null $amount = null): string
    {
        return self::format($amount ?? $this->total_amount, $this->currency->value);
    }

    public static function format(float|string|null $amount, string $currency): string
    {
        return strtoupper($currency).' '.number_format((float) ($amount ?? 0), Money::precision($currency));
    }

    /** "September 2026". */
    public function periodLabel(): string
    {
        return $this->period_start->translatedFormat('F Y');
    }
}
