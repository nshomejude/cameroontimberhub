<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\RfqStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Receipt;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every figure on the buyer account area, as a real query.
 *
 * Ownership is defined exactly once, at the top of this class: a buyer owns the
 * RFQs whose `rfqs.user_id` is theirs, and — transitively — the quotes on those
 * RFQs, the orders awarded on them, and the receipts issued for those orders.
 * Nothing here ever reads an id out of the request, so there is no enumeration
 * surface at all: the account screens are lists, and the detail screens are the
 * existing BuyerRfqAccess-guarded ones.
 *
 * Deliberately NOT modelled, because no column backs them: saved searches,
 * notification/message counts, shipment tracking, contracts, savings
 * percentages and delivery ETAs. The dashboard omits them rather than inventing
 * them. Money is never aggregated across currencies — totals are grouped by the
 * currency they were actually recorded in.
 */
class BuyerDashboard
{
    /** How many rows the dashboard's preview lists show. Keeps queries bounded. */
    public const PREVIEW = 5;

    /** How many entries the derived activity trail shows. */
    public const ACTIVITY = 6;

    /* ------------------------------------------------------------ ownership */

    public function rfqs(User $user): Builder
    {
        return Rfq::query()->where('user_id', $user->getKey());
    }

    /**
     * Buyer-visible quotes on this buyer's RFQs. Supplier drafts and withdrawn
     * quotes are excluded by the model scope, exactly as on the quote screens.
     */
    public function quotes(User $user): Builder
    {
        return Quote::query()
            ->buyerVisible()
            ->whereIn('rfq_id', $this->rfqs($user)->select('rfqs.id'));
    }

    /**
     * Orders on this buyer's RFQs. Scoped through the RFQ rather than through
     * `orders.user_id` so an RFQ adopted after the award still resolves.
     */
    public function orders(User $user): Builder
    {
        return Order::query()->whereIn('rfq_id', $this->rfqs($user)->select('rfqs.id'));
    }

    /** Live (non-voided) receipts for this buyer's orders. */
    public function receipts(User $user): Builder
    {
        return Receipt::query()
            ->whereNull('voided_at')
            ->whereIn('order_id', $this->orders($user)->select('orders.id'));
    }

    /* ---------------------------------------------------------------- stats */

    /**
     * The headline tiles. Each entry carries its own real value and, where the
     * comparison period actually has data, a real month-over-month delta.
     *
     * @return list<array{key: string, label: string, value: int, hint: ?string, delta: ?array{direction: string, percent: int, period: string}, url: ?string, icon: string}>
     */
    public function stats(User $user): array
    {
        $activeRfqs = (clone $this->rfqs($user))
            ->whereIn('status', [RfqStatus::New->value, RfqStatus::InReview->value, RfqStatus::Approved->value])
            ->count();

        $awaiting = (clone $this->quotes($user))
            ->open()
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', now()->toDateString()))
            ->count();

        $activeOrders = (clone $this->orders($user))
            ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
            ->count();

        $inProgress = (clone $this->orders($user))
            ->whereIn('status', [
                OrderStatus::Confirmed->value,
                OrderStatus::InProduction->value,
                OrderStatus::Shipped->value,
            ])
            ->count();

        // A supplier is "engaged" once it has actually quoted. Order suppliers
        // are a strict subset (an order requires an accepted quote), so this
        // single distinct count covers both.
        $suppliers = (clone $this->quotes($user))->distinct()->count('company_id');

        return [
            [
                'key' => 'active_rfqs',
                'label' => 'Active requests',
                'value' => $activeRfqs,
                'hint' => 'Open RFQs still collecting quotes',
                'delta' => $this->delta($this->rfqs($user), 'created_at'),
                'url' => route('account.rfqs'),
                'icon' => 'document-text',
            ],
            [
                'key' => 'quotes_awaiting',
                'label' => 'Quotes to review',
                'value' => $awaiting,
                'hint' => 'Live offers you can still accept or decline',
                'delta' => $this->delta($this->quotes($user), 'submitted_at'),
                'url' => route('account.quotes'),
                'icon' => 'tag',
            ],
            [
                'key' => 'active_orders',
                'label' => 'Active orders',
                'value' => $activeOrders,
                'hint' => 'Awarded and not yet completed or cancelled',
                'delta' => $this->delta($this->orders($user), 'awarded_at'),
                'url' => route('account.orders'),
                'icon' => 'clipboard-document-check',
            ],
            [
                'key' => 'orders_in_progress',
                'label' => 'In progress',
                'value' => $inProgress,
                'hint' => 'Confirmed, in production or shipped',
                'delta' => null,
                'url' => route('account.orders'),
                'icon' => 'cube',
            ],
            [
                'key' => 'suppliers',
                'label' => 'Suppliers engaged',
                'value' => $suppliers,
                'hint' => 'Distinct suppliers that have quoted for you',
                'delta' => null,
                'url' => route('directory'),
                'icon' => 'user-group',
            ],
        ];
    }

    /**
     * Month-over-month change on a real timestamp column. Returns null unless
     * the previous month actually had rows — a percentage against zero is not a
     * fact, it is a flourish.
     *
     * @return array{direction: string, percent: int, period: string}|null
     */
    private function delta(Builder $query, string $column): ?array
    {
        $thisMonth = (clone $query)
            ->whereBetween($column, [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $lastMonth = (clone $query)
            ->whereBetween($column, [
                now()->subMonthNoOverflow()->startOfMonth(),
                now()->subMonthNoOverflow()->endOfMonth(),
            ])
            ->count();

        if ($lastMonth === 0) {
            return null;
        }

        $percent = (int) round((($thisMonth - $lastMonth) / $lastMonth) * 100);

        return [
            'direction' => $percent >= 0 ? 'up' : 'down',
            'percent' => abs($percent),
            'period' => 'vs last month',
        ];
    }

    /**
     * Awarded order value this calendar year, grouped by the currency it was
     * actually recorded in. Never summed across currencies.
     *
     * @return list<array{currency: string, total: string}>
     */
    public function awardedValueThisYear(User $user): array
    {
        return (clone $this->orders($user))
            ->whereNot('status', OrderStatus::Cancelled->value)
            ->where('awarded_at', '>=', now()->startOfYear())
            ->selectRaw('currency, SUM(total_amount) AS total')
            ->groupBy('currency')
            ->orderByDesc('total')
            ->get()
            // `currency` is cast to RfqCurrency on the model, so normalise back
            // to the plain code the views and the trend query work with.
            ->map(fn ($row) => [
                'currency' => $row->currency instanceof \BackedEnum ? (string) $row->currency->value : (string) $row->currency,
                'total' => (string) $row->total,
            ])
            ->all();
    }

    /**
     * Twelve months of awarded order value in one currency, for the inline SVG
     * trend. Returns null when there is not enough real data to draw a trend
     * (fewer than two months with any awarded value) — an empty chart is
     * omitted rather than faked.
     *
     * @return array{currency: string, points: list<array{label: string, month: string, value: float}>, max: float, total: float}|null
     */
    public function valueTrend(User $user): ?array
    {
        $currency = $this->awardedValueThisYear($user)[0]['currency'] ?? null;

        if ($currency === null) {
            return null;
        }

        $start = now()->startOfMonth()->subMonths(11);

        $rows = (clone $this->orders($user))
            ->whereNot('status', OrderStatus::Cancelled->value)
            ->where('currency', $currency)
            ->where('awarded_at', '>=', $start)
            ->selectRaw("to_char(awarded_at, 'YYYY-MM') AS bucket, SUM(total_amount) AS total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        if ($rows->filter(fn ($v) => (float) $v > 0)->count() < 2) {
            return null;
        }

        $points = [];

        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $points[] = [
                'label' => $month->isoFormat('MMM'),
                'month' => $month->isoFormat('MMM YYYY'),
                'value' => (float) ($rows[$key] ?? 0),
            ];
        }

        return [
            'currency' => $currency,
            'points' => $points,
            'max' => max(array_column($points, 'value')),
            'total' => array_sum(array_column($points, 'value')),
        ];
    }

    /**
     * Order counts per status, for the inline donut. Null when there are no
     * orders at all, so the card is omitted rather than drawn empty.
     *
     * @return array{total: int, slices: list<array{status: OrderStatus, count: int, percent: float}>}|null
     */
    public function ordersByStatus(User $user): ?array
    {
        $counts = (clone $this->orders($user))
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $total = (int) $counts->sum();

        if ($total === 0) {
            return null;
        }

        $slices = collect(OrderStatus::cases())
            ->map(fn (OrderStatus $status) => [
                'status' => $status,
                'count' => (int) ($counts[$status->value] ?? 0),
                'percent' => round(((int) ($counts[$status->value] ?? 0)) / $total * 100, 1),
            ])
            ->filter(fn (array $slice) => $slice['count'] > 0)
            ->values()
            ->all();

        return ['total' => $total, 'slices' => $slices];
    }

    /* ------------------------------------------------------- preview lists */

    /** @return Collection<int, Order> */
    public function recentOrders(User $user, int $limit = self::PREVIEW): Collection
    {
        return (clone $this->orders($user))
            ->with(['rfq:id,reference_code,buyer_email,user_id', 'company:id,slug,legal_name,trade_name', 'items:id,order_id,description,quantity,unit'])
            ->orderByDesc('awarded_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Quote> */
    public function recentQuotes(User $user, int $limit = self::PREVIEW): Collection
    {
        return (clone $this->quotes($user))
            ->with(['rfq:id,reference_code,buyer_email,user_id,title', 'company:id,slug,legal_name,trade_name'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Suppliers this buyer has actually ordered from, ranked by real order
     * count. `rating_avg` is a real company column and is shown only when
     * `rating_count` proves it is backed by ratings.
     *
     * @return Collection<int, array{company: ?Company, orders: int}>
     */
    public function topSuppliers(User $user, int $limit = self::PREVIEW): Collection
    {
        $rows = (clone $this->orders($user))
            ->whereNot('status', OrderStatus::Cancelled->value)
            ->selectRaw('company_id, COUNT(*) AS orders_count')
            ->groupBy('company_id')
            ->orderByDesc('orders_count')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        // One extra query for the whole set, never one per row.
        $companies = Company::query()
            ->whereIn('id', $rows->pluck('company_id'))
            ->get(['id', 'slug', 'legal_name', 'trade_name', 'logo_path', 'city', 'country_code', 'rating_avg', 'rating_count'])
            ->keyBy('id');

        return $rows->map(fn ($row) => [
            'company' => $companies->get($row->company_id),
            'orders' => (int) $row->orders_count,
        ]);
    }

    /**
     * A trail of things that really happened, derived from real timestamp
     * columns only (quote submission, order milestone stamps, receipt issue).
     * Nothing is synthesised, and the two source queries are both limited, so
     * the cost does not grow with the buyer's history.
     *
     * @return list<array{at: Carbon, title: string, detail: string, url: ?string, icon: string, tone: string}>
     */
    public function activity(User $user, int $limit = self::ACTIVITY): array
    {
        $entries = [];

        foreach ($this->recentQuotes($user, $limit) as $quote) {
            if ($quote->submitted_at === null) {
                continue;
            }

            $entries[] = [
                'at' => $quote->submitted_at,
                'title' => 'Quote received',
                'detail' => 'From '.($quote->company?->name ?? 'a supplier').' on '.$quote->rfq?->reference_code,
                'url' => $quote->rfq ? route('buyer.rfq.quote', ['rfq' => $quote->rfq_id, 'quote' => $quote->getKey()]) : null,
                'icon' => 'document-text',
                'tone' => 'timber',
            ];
        }

        foreach ($this->recentOrders($user, $limit) as $order) {
            $stamp = collect([
                'completed' => $order->completed_at,
                'cancelled' => $order->cancelled_at,
                'delivered' => $order->delivered_at,
                'shipped' => $order->shipped_at,
                'in production' => $order->production_started_at,
                'confirmed' => $order->confirmed_at,
                'awarded' => $order->awarded_at,
            ])->filter()->sortDesc();

            if ($stamp->isEmpty()) {
                continue;
            }

            $entries[] = [
                'at' => $stamp->first(),
                'title' => 'Order '.$stamp->keys()->first(),
                'detail' => $order->reference_code.' · '.$order->supplier_name,
                'url' => route('buyer.rfq.order', ['rfq' => $order->rfq_id]),
                'icon' => 'clipboard-document-check',
                'tone' => 'forest',
            ];
        }

        usort($entries, fn (array $a, array $b) => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return array_slice($entries, 0, $limit);
    }

    /* ------------------------------------------------------------ listings */

    public function rfqPage(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return $this->rfqs($user)
            ->with(['items:id,rfq_id,species_id,species_text,form,quantity,unit', 'items.species:id,common_name'])
            ->withCount(['quotes as quotes_count' => fn (Builder $q) => $q->buyerVisible()])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function quotePage(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return $this->quotes($user)
            ->with(['rfq:id,reference_code,buyer_email,user_id,title', 'company:id,slug,legal_name,trade_name'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function orderPage(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return $this->orders($user)
            // `conversation` so the reorder link on a delivered/completed row
            // can point at the thread without a query per row.
            ->with([
                'rfq:id,reference_code,buyer_email,user_id',
                'company:id,slug,legal_name,trade_name',
                'receipt',
                'conversation:id,order_id',
            ])
            ->orderByDesc('awarded_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function receiptPage(User $user, int $perPage = 10): LengthAwarePaginator
    {
        return $this->receipts($user)
            ->with(['order:id,rfq_id,reference_code,supplier_name,status', 'order.rfq:id,reference_code,buyer_email,user_id'])
            ->orderByDesc('issued_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** True when this buyer has no activity at all — drives the empty state. */
    public function isEmpty(User $user): bool
    {
        return ! $this->rfqs($user)->exists();
    }

    /** "USD 1,245,680.00" — currency code up front, never a guessed symbol. */
    public static function money(string $currency, float|string|null $amount): string
    {
        return $currency.' '.number_format((float) $amount, 2);
    }

    /** Compact axis label for the trend chart: "USD 1.2M" / "USD 900K". */
    public static function compact(string $currency, float $amount): string
    {
        return $currency.' '.match (true) {
            $amount >= 1_000_000 => rtrim(rtrim(number_format($amount / 1_000_000, 1, '.', ''), '0'), '.').'M',
            $amount >= 1_000 => rtrim(rtrim(number_format($amount / 1_000, 1, '.', ''), '0'), '.').'K',
            default => number_format($amount, 0),
        };
    }
}
