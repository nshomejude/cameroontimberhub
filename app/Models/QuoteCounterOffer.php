<?php

namespace App\Models;

use App\Enums\CounterOfferStatus;
use App\Enums\RfqCurrency;
use App\Enums\RfqIncoterm;
use App\Enums\RfqUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One round of a negotiation over a quote — see the migration for why this is
 * a ledger of rounds rather than a chain of Quote rows.
 */
class QuoteCounterOffer extends Model
{
    use HasFactory;

    public const PARTY_BUYER = 'buyer';

    public const PARTY_SUPPLIER = 'supplier';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CounterOfferStatus::class,
            'currency' => RfqCurrency::class,
            'incoterm' => RfqIncoterm::class,
            'unit' => RfqUnit::class,
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'lead_time_days' => 'integer',
            'responded_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    /** The revised quote this round produced, if it was accepted. */
    public function resultingQuote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'resulting_quote_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CounterOfferStatus::Pending->value);
    }

    /* ----------------------------------------------------------- helpers */

    public function isPending(): bool
    {
        return $this->status === CounterOfferStatus::Pending;
    }

    public function fromBuyer(): bool
    {
        return $this->party === self::PARTY_BUYER;
    }

    /**
     * The party that owes an answer. Only they may accept, decline or counter —
     * this is the single place that rule is expressed.
     */
    public function awaitingParty(): string
    {
        return $this->fromBuyer() ? self::PARTY_SUPPLIER : self::PARTY_BUYER;
    }

    /** "USD 590,000.00" — code up front, never a guessed symbol. */
    public function money(float|string|null $amount): string
    {
        return $this->currency->value.' '.number_format((float) $amount, 2);
    }

    public function quantityLabel(): ?string
    {
        if ($this->quantity === null) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $this->quantity, 2, '.', ''), '0'), '.')
            .' '.($this->unit?->label() ?? '');
    }

    /** Quantity x unit price, half-up at 2dp — never a posted total. */
    public static function total(float|string|null $quantity, float|string|null $unitPrice): string
    {
        return Quote::lineTotal($quantity ?: 1, $unitPrice);
    }
}
