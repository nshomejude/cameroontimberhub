<?php

namespace App\Services;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Order lifecycle state machine, mirroring QuoteService / RfqTriageService.
 *
 * transition() is the only place `status` is written, illegal moves throw, and
 * every move is written to the activity log.
 */
class OrderService
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'awarded' => ['confirmed', 'cancelled'],
        'confirmed' => ['in_production', 'shipped', 'cancelled'],
        'in_production' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'cancelled'],
        'delivered' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function __construct(
        private readonly OrderReferenceGenerator $references,
        private readonly InventoryService $inventory,
    ) {}

    /* ------------------------------------------------------------ creation */

    /**
     * The only way an order comes into existence.
     *
     * Requires an accepted quote. Idempotent: a second call returns the
     * existing order rather than minting a duplicate, and the database backs
     * that up with a unique index on `orders.quote_id`, so two concurrent
     * accepts cannot both win the PHP-side race.
     *
     * Everything commercial is copied here and never re-read: the buyer's
     * details, the supplier's name, and each line's description, spec,
     * quantity, unit, unit price and line total. A later edit to the quote,
     * the catalogue or a company profile leaves this order untouched.
     */
    public function createFromQuote(Quote $quote, ?User $actor = null): Order
    {
        if ($quote->status !== QuoteStatus::Accepted) {
            throw new RuntimeException('An order can only be created from an accepted quote.');
        }

        return DB::transaction(function () use ($quote, $actor) {
            $existing = Order::where('quote_id', $quote->getKey())->first();

            if ($existing) {
                return $existing;
            }

            $quote->loadMissing(['rfq', 'company', 'items.species']);
            $rfq = $quote->rfq;
            $company = $quote->company;

            $order = new Order([
                'quote_id' => $quote->getKey(),
                'rfq_id' => $rfq->getKey(),
                'company_id' => $company->getKey(),
                'user_id' => $rfq->user_id,
                'reference_code' => $this->references->order(),
                'status' => OrderStatus::Awarded,

                // ---- snapshots ----
                'buyer_name' => $rfq->buyer_name,
                'buyer_company' => $rfq->buyer_company,
                'buyer_email' => $rfq->buyer_email,
                'buyer_country_code' => $rfq->buyer_country_code,
                'supplier_name' => $company->name,

                'currency' => $quote->currency,
                'shipping_amount' => $quote->shipping_amount,
                'tax_amount' => $quote->tax_amount,
                'subtotal_amount' => 0,
                'total_amount' => 0,

                'payment_status' => OrderPaymentStatus::Unpaid,
                'amount_paid' => 0,

                'incoterm' => $quote->incoterm?->value,
                'payment_terms' => $quote->payment_terms,
                'lead_time_days' => $quote->lead_time_days,
                'destination_country_code' => $rfq->destination_country_code,
                'shipping_port' => $rfq->shipping_port,
                // Only a real, derivable date: award date + the lead time the
                // supplier actually quoted. Null when they quoted no lead time.
                'expected_delivery_at' => $quote->lead_time_days
                    ? now()->addDays($quote->lead_time_days)->toDateString()
                    : null,
                'buyer_notes' => $rfq->notes,
                'awarded_at' => now(),

                // Provenance only. Carried from the RFQ so a card can say
                // "Reorder" without walking back through the quote; it grants
                // nothing and no figure is inherited with it.
                'reorder_of_order_id' => $rfq->reorder_of_order_id,
            ]);

            $order->save();

            foreach ($quote->items as $item) {
                /** @var QuoteItem $item */
                $order->items()->create([
                    'quote_item_id' => $item->getKey(),
                    'species_id' => $item->species_id,
                    'species_name' => $item->species?->common_name,
                    'description' => $item->description,
                    'form' => $item->form,
                    'grade' => $item->grade,
                    'dimensions' => $item->dimensions,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                ]);
            }

            $order->load('items')->recalculateTotals()->save();

            foreach ($order->items as $orderItem) {
                $this->reserveInventoryForItem($order, $orderItem);
            }

            $this->issueReceipt($order);

            $log = activity('order')->performedOn($order)->event('created')
                ->withProperties([
                    'quote_id' => $quote->getKey(),
                    'reference_code' => $order->reference_code,
                    'total_amount' => (string) $order->total_amount,
                ]);

            if ($actor instanceof User) {
                $log->causedBy($actor);
            }

            $log->log('Order created from accepted quote');

            return $order->refresh();
        });
    }

    /**
     * Reserve inventory for one order line, opt-in per product (brief §4,
     * gap-plan 1.5.6).
     *
     * Order/quote line items carry a species, not a product — a listing is
     * resolved by matching the order's supplier (company_id) against a
     * product for that species. Most products have no tracked Inventory row
     * yet, so a miss here is a silent no-op, not an error. When a row does
     * exist but does not hold enough quantity, this is deliberately
     * non-blocking: inventory tracking is new and must not be able to break
     * order creation on day one, so the shortfall is only logged.
     */
    private function reserveInventoryForItem(Order $order, OrderItem $orderItem): void
    {
        if ($orderItem->species_id === null) {
            return;
        }

        $product = Product::query()
            ->where('company_id', $order->company_id)
            ->where('species_id', $orderItem->species_id)
            ->first();

        if (! $product) {
            return;
        }

        $inventory = Inventory::query()->where('product_id', $product->getKey())->first();

        if (! $inventory) {
            return;
        }

        try {
            $this->inventory->reserve($inventory, (float) $orderItem->quantity);
        } catch (RuntimeException $e) {
            Log::warning('Order created despite insufficient inventory.', [
                'order_id' => $order->getKey(),
                'order_item_id' => $orderItem->getKey(),
                'inventory_id' => $inventory->getKey(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Issue the order's verifiable receipt. Idempotent — an order has at most
     * one live receipt, enforced by a partial unique index.
     */
    public function issueReceipt(Order $order): Receipt
    {
        $live = $order->receipts()->whereNull('voided_at')->first();

        if ($live) {
            return $live;
        }

        return $order->receipts()->create([
            'receipt_number' => $this->references->receipt(),
            'verification_token' => $this->references->verificationToken(),
            'issued_at' => now(),
            'amount' => $order->total_amount,
            'currency' => $order->currency,
        ]);
    }

    /* --------------------------------------------------------- transitions */

    public function transition(Order $order, OrderStatus $to, ?Model $actor = null, ?string $reason = null): Order
    {
        $from = $order->status;

        if (! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw new RuntimeException("Illegal order transition {$from->value} -> {$to->value}");
        }

        $data = ['status' => $to];

        match ($to) {
            OrderStatus::Confirmed => $data['confirmed_at'] = now(),
            OrderStatus::InProduction => $data['production_started_at'] = now(),
            OrderStatus::Shipped => $data['shipped_at'] = now(),
            OrderStatus::Delivered => $data['delivered_at'] = now(),
            OrderStatus::Completed => $data['completed_at'] = now(),
            OrderStatus::Cancelled => $data['cancelled_at'] = now(),
            default => null,
        };

        if ($to === OrderStatus::Cancelled) {
            $data['cancellation_reason'] = $reason;
        }

        $order->update($data);

        $log = activity('order')->performedOn($order)->event('status_changed')
            ->withProperties(['from' => $from->value, 'to' => $to->value, 'reason' => $reason]);

        if ($actor instanceof User) {
            $log->causedBy($actor);
        }

        $log->log("Order status -> {$to->value}");

        return $order->refresh();
    }

    public function confirm(Order $order, ?User $actor = null): Order
    {
        return $this->transition($order, OrderStatus::Confirmed, $actor);
    }

    public function startProduction(Order $order, ?User $actor = null): Order
    {
        return $this->transition($order, OrderStatus::InProduction, $actor);
    }

    /** Cannot ship an order the supplier has not confirmed. */
    public function ship(Order $order, ?User $actor = null): Order
    {
        return $this->transition($order, OrderStatus::Shipped, $actor);
    }

    public function deliver(Order $order, ?User $actor = null): Order
    {
        return $this->transition($order, OrderStatus::Delivered, $actor);
    }

    public function complete(Order $order, ?User $actor = null): Order
    {
        return $this->transition($order, OrderStatus::Completed, $actor);
    }

    /** Cannot cancel an order that is already completed or cancelled. */
    public function cancel(Order $order, string $reason, ?User $actor = null): Order
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A cancellation reason is required.');
        }

        return $this->transition($order, OrderStatus::Cancelled, $actor, trim($reason));
    }

    /* ---------------------------------------------------------- settlement */

    /**
     * Record a payment that happened OFF this platform. There is no payment
     * integration; this exists so staff can reflect reality, and it is the only
     * thing that ever moves an order out of "no payment recorded".
     */
    public function recordPayment(
        Order $order,
        float|string $amountPaid,
        ?string $method = null,
        ?User $actor = null,
    ): Order {
        $amount = round((float) $amountPaid, 2);

        if ($amount < 0) {
            throw new RuntimeException('A recorded payment cannot be negative.');
        }

        if ($amount > (float) $order->total_amount) {
            throw new RuntimeException('A recorded payment cannot exceed the order total.');
        }

        $status = match (true) {
            $amount <= 0.0 => OrderPaymentStatus::Unpaid,
            $amount >= (float) $order->total_amount => OrderPaymentStatus::Paid,
            default => OrderPaymentStatus::PartiallyPaid,
        };

        $order->update([
            'amount_paid' => number_format($amount, 2, '.', ''),
            'payment_status' => $status,
            'payment_method' => $method,
            'payment_recorded_at' => $amount > 0 ? now() : null,
        ]);

        $log = activity('order')->performedOn($order)->event('payment_recorded')
            ->withProperties(['amount_paid' => number_format($amount, 2, '.', ''), 'payment_status' => $status->value, 'method' => $method]);

        if ($actor instanceof User) {
            $log->causedBy($actor);
        }

        $log->log('Off-platform payment recorded');

        return $order->refresh();
    }
}
