<?php

namespace App\Models;

use App\Enums\CreditSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row in a company's non-cash credit ledger (billing engine M8, plan
 * §19). Append-only — see App\Services\Billing\CreditLedger for why a
 * mutable balance column was rejected in favour of a ledger.
 */
class Credit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'source' => CreditSource::class,
            'expires_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        $at = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        return $this->expires_at !== null && $this->expires_at->lt($at);
    }
}
