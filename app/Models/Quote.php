<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Enums\RfqCurrency;
use App\Enums\RfqIncoterm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
     * Recompute every derived money value from the line items. The single
     * source of truth for totals — never trust a posted subtotal or total.
     */
    public function recalculateTotals(): static
    {
        $subtotal = $this->items->reduce(
            fn ($carry, QuoteItem $item) => bcadd((string) $carry, self::lineTotal($item->quantity, $item->unit_price), 2),
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

    /** Quantity x unit price, half-up to 2dp, as a decimal string. */
    public static function lineTotal(float|string|null $quantity, float|string|null $unitPrice): string
    {
        // bcmul truncates, so round half-up at 2dp via an extra digit of scale.
        $raw = bcmul(number_format((float) $quantity, 4, '.', ''), number_format((float) $unitPrice, 4, '.', ''), 6);

        return number_format((float) $raw, 2, '.', '');
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
