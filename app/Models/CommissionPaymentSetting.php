<?php

namespace App\Models;

use App\Enums\CommissionDepositMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Singleton row holding the platform's commission payment instructions —
 * where suppliers send the commission billed on their monthly statements
 * (owner decision 2026-10-01: manual MTN MoMo / Orange Money transfer or bank
 * deposit only). Edited by finance (`payments.manage`) from /admin →
 * Commission payment instructions; same single-row pattern as
 * App\Models\ReferralSetting. None of this is secret (it is printed on every
 * statement), so nothing is encrypted.
 */
class CommissionPaymentSetting extends Model
{
    protected $guarded = ['id'];

    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? self::create([]);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** True when at least one payment channel is fully filled in. */
    public function isConfigured(): bool
    {
        return $this->instructions() !== [];
    }

    /**
     * The configured payment channels, ready to print on a statement / return
     * from the API. A channel is listed only when its essentials are set
     * (number + name for MoMo / Orange Money; bank name + account name +
     * account number or IBAN for a bank).
     *
     * @return list<array{method: string, label: string, details: array<string, string>}>
     */
    public function instructions(): array
    {
        $out = [];

        if (filled($this->mtn_momo_number) && filled($this->mtn_momo_name)) {
            $out[] = [
                'method' => CommissionDepositMethod::MtnMomo->value,
                'label' => CommissionDepositMethod::MtnMomo->label(),
                'details' => ['number' => (string) $this->mtn_momo_number, 'account_name' => (string) $this->mtn_momo_name],
            ];
        }

        if (filled($this->orange_money_number) && filled($this->orange_money_name)) {
            $out[] = [
                'method' => CommissionDepositMethod::OrangeMoney->value,
                'label' => CommissionDepositMethod::OrangeMoney->label(),
                'details' => ['number' => (string) $this->orange_money_number, 'account_name' => (string) $this->orange_money_name],
            ];
        }

        if (filled($this->bank_name) && filled($this->bank_account_name) && (filled($this->bank_account_number) || filled($this->bank_iban))) {
            $out[] = [
                'method' => CommissionDepositMethod::Bank->value,
                'label' => CommissionDepositMethod::Bank->label(),
                'details' => array_filter([
                    'bank_name' => (string) $this->bank_name,
                    'account_name' => (string) $this->bank_account_name,
                    'account_number' => (string) $this->bank_account_number,
                    'iban' => (string) $this->bank_iban,
                    'swift' => (string) $this->bank_swift,
                    'branch' => (string) $this->bank_branch,
                ], fn (string $v) => $v !== ''),
            ];
        }

        return $out;
    }
}
