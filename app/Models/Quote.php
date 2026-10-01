<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Enums\RfqCurrency;
use App\Enums\RfqIncoterm;
use App\Support\Money;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'currency' => RfqCurrency::class,
            'incoterm' => RfqIncoterm::class,
            'subtotal_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'lead_time_days' => 'integer',
            'validity_days' => 'integer',
            'revision' => 'integer',
            'valid_until' => 'date',
            'submitted_at' => 'datetime',
            'viewed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function routing(): BelongsTo
    {
        return $this->belongsTo(RfqCompany::class, 'rfq_company_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    /** The order minted when this quote was accepted. At most one, ever. */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /** Negotiation rounds recorded against this quote, oldest first. */
    public function counterOffers(): HasMany
    {
        return $this->hasMany(QuoteCounterOffer::class)->orderBy('id');
    }

    /** The quote this one replaced after a negotiation settled. */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_quote_id');
    }

    /** The revision that replaced this quote, if any. */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_quote_id');
    }

    /** The recorded acceptance of this quote's terms. At most one. */
    public function acceptance(): HasOne
    {
        return $this->hasOne(ContractAcceptance::class);
    }

    /* ------------------------------------------------------------- scopes */

    /**
     * Only what a buyer may see. Drafts and withdrawn quotes never leave the
     * supplier's side, so every buyer-facing query goes through this.
     */
    public function scopeBuyerVisible(Builder $query): Builder
    {
        return $query->whereIn('status', QuoteStatus::buyerVisible());
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [QuoteStatus::Submitted->value, QuoteStatus::Viewed->value]);
    }

    /**
     * Preload the chat thread id QuoteResource::conversationIdFor() would
     * compute (quotation-card message first, else conversations.quote_id;
     * latest wins) as `resolved_conversation_id`, in the same query — so
     * listing N quotes costs no extra queries per row.
     */
    public function scopeWithConversationId(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->getTable().'.*');
        }

        $table = $query->getModel()->getTable();

        $viaCard = Message::query()
            ->selectRaw('max(conversation_id)')
            ->where('related_type', $this->getMorphClass())
            ->whereColumn('related_id', $table.'.id')
            ->toBase();

        $direct = Conversation::query()
            ->selectRaw('max(id)')
            ->whereColumn('quote_id', $table.'.id')
            ->toBase();

        return $query->selectRaw(
            'coalesce(('.$viaCard->toSql().'), ('.$direct->toSql().')) as resolved_conversation_id',
            [...$viaCard->getBindings(), ...$direct->getBindings()],
        );
    }

    /* ------------------------------------------------------------- helpers */

    public function isExpired(): bool
    {
        return $this->status === QuoteStatus::Expired
            || ($this->valid_until !== null && $this->valid_until->isPast());
    }

    /** True when the buyer may still accept or decline this quote. */
    public function isActionable(): bool
    {
        return $this->status->isOpen() && ! $this->isExpired();
    }

    /**
     * True when this quote was withdrawn *because* a negotiation replaced it.
     *
     * Uses the loaded relation when it is there so a thread full of quotation
     * cards does not fire one query per card.
     */
    public function isSuperseded(): bool
    {
        return $this->relationLoaded('supersededBy')
            ? $this->supersededBy !== null
            : $this->supersededBy()->exists();
    }

    /**
     * Status as it should read in a thread. Identical to the raw status in
     * every case but one: a quote withdrawn to make way for its own revision is
     * "Revised", because "Withdrawn" would say the supplier walked away.
     */
    public function threadStatusLabel(): string
    {
        return $this->status === QuoteStatus::Withdrawn && $this->isSuperseded()
            ? 'Revised'
            : $this->status->label();
    }

    public function threadStatusColor(): string
    {
        return $this->status === QuoteStatus::Withdrawn && $this->isSuperseded()
            ? 'info'
            : $this->status->color();
    }

    /**
     * Recompute every derived money value from the line items. The single
     * source of truth for totals — never trust a posted subtotal or total.
     */
    public function recalculateTotals(): static
    {
        $subtotal = $this->items->reduce(
            fn ($carry, QuoteItem $item) => bcadd((string) $carry, self::lineTotal($item->quantity, $item->unit_price, $this->currency), 2),
            '0.00',
        );

        $total = bcadd(
            bcadd($subtotal, (string) ($this->shipping_amount ?? '0.00'), 2),
            (string) ($this->tax_amount ?? '0.00'),
            2,
        );

        $this->subtotal_amount = $subtotal;
        $this->total_amount = $total;

        return $this;
    }

    /**
     * Quantity x unit price, rounded half-up PER LINE to the currency's real
     * precision — whole francs for XAF/XOF, cents otherwise (2.5 m³ × 10,001
     * XAF = 25,002.5 → "25003.00") — so a subtotal is always a sum of
     * payable amounts. bcmath throughout; no currency = 2dp (legacy callers).
     */
    public static function lineTotal(float|string|null $quantity, float|string|null $unitPrice, BackedEnum|string|null $currency = null): string
    {
        $raw = bcmul(self::decimal($quantity), self::decimal($unitPrice), 8);

        return Money::forCurrency($raw, $currency);
    }

    /** A float/string/null amount as a plain decimal string bcmath accepts. */
    private static function decimal(float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        return is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1
            ? $value
            : number_format((float) $value, 6, '.', '');
    }

    /** Total quantity across all line items (units may differ — label per row). */
    public function totalQuantity(): string
    {
        return rtrim(rtrim(number_format(
            (float) $this->items->sum(fn (QuoteItem $i) => (float) $i->quantity), 2, '.', ''
        ), '0'), '.');
    }

    /** "USD 18,500.00" — currency code up front, never a guessed symbol. */
    public function money(float|string|null $amount): string
    {
        return $this->currency->value.' '.number_format((float) $amount, 2);
    }

    /** Days left before the offer lapses, or null when open-ended. */
    public function daysRemaining(): ?int
    {
        if ($this->valid_until === null) {
            return null;
        }

        return max(0, (int) now()->startOfDay()->diffInDays($this->valid_until->startOfDay(), false));
    }
}
