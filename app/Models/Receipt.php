<?php

namespace App\Models;

use App\Enums\RfqCurrency;
use App\Models\Concerns\ChainsIntegrity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The platform's issued, third-party-verifiable record of an order.
 *
 * It attests that this platform issued a document, for this order, for this
 * amount, on this date. It is *not* a proof of payment — this platform settles
 * no money — and the buyer-facing and verifier-facing copy both say so.
 */
class Receipt extends Model
{
    use ChainsIntegrity;
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * The issued facts the integrity hash chain attests to (Task B3). Void
     * status and verification telemetry are deliberately NOT here: the
     * receipt's issued facts are frozen, void status is separate mutable
     * state shown alongside on the verification page.
     *
     * @return list<string>
     */
    public function integrityPayloadColumns(): array
    {
        return ['receipt_number', 'order_id', 'issued_at', 'amount', 'currency'];
    }

    /**
     * The token is the whole security of the verification endpoint, so it never
     * leaves the server except in a URL we generate. Hiding it here means an
     * accidental `toArray()`/`toJson()` in a view or API cannot spill it.
     */
    protected $hidden = ['verification_token'];

    protected function casts(): array
    {
        return [
            'currency' => RfqCurrency::class,
            'amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'verified_at' => 'datetime',
            'voided_at' => 'datetime',
            'verification_count' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }

    /** What a verifier is told: AUTHENTIC or VOID. Nothing in between. */
    public function verificationStatus(): string
    {
        return $this->isVoid() ? 'VOID' : 'AUTHENTIC';
    }

    public function money(float|string|null $amount = null): string
    {
        return $this->currency->value.' '.number_format((float) ($amount ?? $this->amount), 2);
    }

    /** The public URL a QR code / printed link points at. */
    public function verificationUrl(): string
    {
        return route('receipts.verify.token', ['token' => $this->verification_token]);
    }
}
