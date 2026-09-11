<?php

namespace App\Services\Billing;

use App\Enums\CreditSource;
use App\Models\Company;
use App\Models\Credit;
use App\Models\User;
use RuntimeException;

/**
 * Per-company non-cash credit balance, kept as an append-only ledger
 * (billing engine M8, plan §19) rather than a mutable balance column:
 *
 *   - History stays reconstructible — "what was the balance on date X"
 *     is answerable by summing rows up to X, exactly like a bank ledger.
 *   - No lost-update race between two concurrent grants/consumptions
 *     silently clobbering a single counter.
 *   - A correction is a new row (positive or negative), never an edit of
 *     an old one — matching the platform's historical-immutability rule
 *     (plan §2) and the audit trail every other money-adjacent model here
 *     keeps (TaxRule, Invoice via ChainsIntegrity).
 *   - Currencies are never summed together — a company holding both XAF
 *     and USD credit keeps two independent balances.
 */
class CreditLedger
{
    /** Sum of non-expired credit rows for $company, in bcmath. */
    public function balance(Company $company, ?string $currency = null): string
    {
        $query = Credit::query()
            ->where('company_id', $company->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()));

        if ($currency !== null) {
            $query->where('currency', strtoupper($currency));
        }

        $sum = '0';
        foreach ($query->get(['amount']) as $row) {
            $sum = bcadd($sum, (string) $row->amount, 2);
        }

        return $this->scale2($sum);
    }

    public function grant(
        Company $company,
        string $amount,
        string $currency,
        string $reason,
        CreditSource $source,
        ?User $by = null,
        ?\DateTimeInterface $expiresAt = null,
    ): Credit {
        $amount = $this->scale2($amount);

        if (bccomp($amount, '0', 2) !== 1) {
            throw new RuntimeException('A credit grant amount must be positive.');
        }

        $credit = Credit::create([
            'company_id' => $company->id,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'reason' => $reason,
            'source' => $source,
            'granted_by' => $by?->id,
            'expires_at' => $expiresAt,
        ]);

        activity('credit')
            ->performedOn($credit)
            ->causedBy($by)
            ->event('granted')
            ->withProperties(['company_id' => $company->id, 'amount' => $amount, 'currency' => $credit->currency, 'source' => $source->value])
            ->log("Credit granted to company #{$company->id}");

        return $credit;
    }

    public function consume(Company $company, string $amount, string $currency, string $reason): Credit
    {
        $amount = $this->scale2($amount);
        $currency = strtoupper($currency);

        if (bccomp($amount, '0', 2) !== 1) {
            throw new RuntimeException('A credit consumption amount must be positive.');
        }

        $balance = $this->balance($company, $currency);

        if (bccomp($amount, $balance, 2) === 1) {
            throw new RuntimeException("Company #{$company->id} has insufficient {$currency} credit balance ({$balance}) to consume {$amount}.");
        }

        $credit = Credit::create([
            'company_id' => $company->id,
            'amount' => bcmul($amount, '-1', 2),
            'currency' => $currency,
            'reason' => $reason,
            'source' => CreditSource::AdminGrant,
        ]);

        activity('credit')
            ->performedOn($credit)
            ->event('consumed')
            ->withProperties(['company_id' => $company->id, 'amount' => $amount, 'currency' => $currency])
            ->log("Credit consumed by company #{$company->id}");

        return $credit;
    }

    private function scale2(string $n): string
    {
        return bcadd($n, '0', 2);
    }
}
