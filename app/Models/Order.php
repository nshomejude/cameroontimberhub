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
            'payment_due_at' => 'date',
            'etd' => 'date',
            'eta' => 'date',
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

    /** Proof-of-delivery and shipping papers uploaded against this order. */
    public function documents(): HasMany
    {
        return $this->hasMany(OrderDocument::class)->orderBy('id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(CompanyReview::class);
    }

    /** Trade Assurance Phase 1 coordination record (blueprint §28), if any. */
    public function tradeAssuranceAgreement(): HasOne
    {
        return $this->hasOne(TradeAssuranceAgreement::class);
    }

    /**
     * The earlier order this one repeats, when it came from a reorder request.
     *
     * Provenance, nothing more: this order's money, terms and status are its
     * own, copied from its own accepted quote at award time exactly like any
     * other order.
     */
    public function reorderOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reorder_of_order_id');
    }

    public function isReorder(): bool
    {
        return $this->reorder_of_order_id !== null;
    }

    /**
     * The conversation this order is attached to, if the parties have one.
     *
     * Used to send a buyer from `/account/orders` to the place a reorder
     * actually happens. A reorder is a conversation between two parties, not a
     * one-click purchase, so the account screen links into the thread rather
     * than starting anything itself.
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * The shipment facts a human actually typed in, as label => value pairs,
     * with every empty one dropped.
     *
     * This is the honest core of the tracking card. There is no carrier API
     * here: if the supplier has not entered a carrier, the caller gets no
     * "Carrier" key at all, so the card renders no empty label rather than the
     * mockup's "To be assigned" placeholder.
     *
     * @return array<string, string>
     */
    public function shipmentFacts(): array
    {
        $facts = [
            'Carrier' => $this->carrier,
            'Tracking number' => $this->tracking_number,
            'Shipping method' => $this->shipping_method,
            'Vessel' => $this->vessel_name,
            'Voyage' => $this->voyage_number,
            'Container' => $this->container_number,
            'Port of loading' => $this->port_of_loading,
            'Port of discharge' => $this->port_of_discharge,
            'Departed' => $this->etd?->isoFormat('D MMM YYYY'),
            'Estimated arrival' => $this->eta?->isoFormat('D MMM YYYY'),
        ];

        return array_filter(
            array_map(fn ($value) => is_string($value) ? trim($value) : $value, $facts),
            fn ($value) => $value !== null && $value !== '',
        );
    }

    public function hasShipmentFacts(): bool
    {
        return $this->shipmentFacts() !== [];
    }

    /**
     * The supplier's tracking link, only when it is a real absolute http(s)
     * URL. A carrier reference typed into the wrong box never becomes an
     * `href`, and a `javascript:` value is refused outright.
     */
    public function trackingLink(): ?string
    {
        $url = trim((string) $this->tracking_url);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }

    /**
     * Delivery facts recorded when the supplier marked it delivered.
     *
     * @return array<string, string>
     */
    public function deliveryFacts(): array
    {
        return array_filter([
            'Delivered on' => $this->delivered_at?->isoFormat('D MMM YYYY, h:mm A'),
            'Received by' => $this->delivered_to_name,
            'Delivery location' => $this->delivery_location,
        ], fn ($value) => $value !== null && trim((string) $value) !== '');
    }

    /** True once the buyer may leave a review: the order is closed. */
    public function isReviewable(): bool
    {
        return $this->status === OrderStatus::Completed;
    }

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
