<?php

namespace App\Models;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\RfqCurrency;
use App\Enums\RfqIncoterm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A placed order. Created only by OrderService::createFromQuote() when a buyer
 * accepts a quote.
 *
 * Every commercial value on this row and its items is a *snapshot* taken at
 * award time. The quote and company relations exist for provenance and
 * navigation; they are never the source of money, names, or specifications
 * shown on an order, a receipt, or a verification.
 */
class Order extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => OrderPaymentStatus::class,
            'currency' => RfqCurrency::class,
            'incoterm' => RfqIncoterm::class,
            'subtotal_amount' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'lead_time_days' => 'integer',
            'expected_delivery_at' => 'date',
            'payment_recorded_at' => 'datetime',
            'awarded_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'production_started_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /** The one live (non-voided) receipt for this order, if any. */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class)->whereNull('voided_at');
    }

    /* ----------------------------------------------------------- helpers */

    /** "USD 18,500.00" — currency code up front, never a guessed symbol. */
    public function money(float|string|null $amount): string
    {
        return $this->currency->value.' '.number_format((float) $amount, 2);
    }

    /** Outstanding balance against what has actually been recorded as paid. */
    public function balanceDue(): string
    {
        return number_format(max(0, (float) $this->total_amount - (float) $this->amount_paid), 2, '.', '');
    }

    /** Total quantity across all lines (units may differ — label per row). */
    public function totalQuantity(): string
    {
        return rtrim(rtrim(number_format(
            (float) $this->items->sum(fn (OrderItem $i) => (float) $i->quantity), 2, '.', ''
        ), '0'), '.');
    }

    /**
     * Recompute the header money from the snapshot lines. Used at creation and
     * nowhere else — an existing order's figures are immutable.
     */
    public function recalculateTotals(): static
    {
        $subtotal = $this->items->reduce(
            fn ($carry, OrderItem $item) => bcadd((string) $carry, (string) $item->line_total, 2),
            '0.00',
        );

        $this->subtotal_amount = $subtotal;
        $this->total_amount = bcadd(
            bcadd($subtotal, (string) ($this->shipping_amount ?? '0.00'), 2),
            (string) ($this->tax_amount ?? '0.00'),
            2,
        );

        return $this;
    }

    /**
     * Milestone trail for the buyer screens. `at` is null until the milestone
     * actually happens — no projected or invented dates.
     *
     * @return list<array{status: OrderStatus, at: ?Carbon, reached: bool}>
     */
    public function milestones(): array
    {
        $stamps = [
            OrderStatus::Awarded->value => $this->awarded_at,
            OrderStatus::Confirmed->value => $this->confirmed_at,
            OrderStatus::InProduction->value => $this->production_started_at,
            OrderStatus::Shipped->value => $this->shipped_at,
            OrderStatus::Delivered->value => $this->delivered_at,
            OrderStatus::Completed->value => $this->completed_at,
        ];

        return collect(OrderStatus::milestones())
            ->map(fn (OrderStatus $status) => [
                'status' => $status,
                'at' => $stamps[$status->value] ?? null,
                'reached' => ($stamps[$status->value] ?? null) !== null,
            ])
            ->all();
    }
}
