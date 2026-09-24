<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\QuoteStatus;
use App\Enums\RfqCompanyStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\RfqCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Every figure on the supplier's dashboard feed, as a real query — the
 * exporter-panel counterpart of BuyerDashboard.
 *
 * Ownership is resolved exactly the way SupplierApiScope already does for
 * the rest of the supplier API surface (routed RFQs via `rfq_company`,
 * quotes via `quotes.company_id`, orders via `orders.company_id`) — this
 * class is built ON TOP of SupplierApiScope rather than re-deriving those
 * boundaries, so there is exactly one definition of "what belongs to this
 * supplier's company" across `/supplier/*` and `/dashboard`.
 *
 * `stats`/`recent_orders`/`recent_quotes` are shaped like BuyerDashboard's
 * equivalents by key name (`key`/`label`/`value`/`hint`/`delta`/`icon` for
 * stats; the same resource classes for the preview lists) so the mobile
 * client's rendering code does not need a second branch per role for the
 * shared concepts.
 */
class SupplierDashboard
{
    /** How many rows the dashboard's preview lists show — same bound as BuyerDashboard. */
    public const PREVIEW = 5;

    /** How many entries the derived activity trail shows. */
    public const ACTIVITY = 6;

    public function __construct(private readonly SupplierApiScope $scope) {}

    /* ------------------------------------------------------------ ownership */

    public function company(User $user): ?Company
    {
        return $this->scope->company($user);
    }

    public function routings(User $user): Builder
    {
        $companyId = $this->company($user)?->getKey();

        return RfqCompany::query()->where('company_id', $companyId);
    }

    public function quotes(User $user): Builder
    {
        return $this->scope->quotes($user);
    }

    public function orders(User $user): Builder
    {
        return $this->scope->orders($user);
    }

    public function products(User $user): Builder
    {
        $companyId = $this->company($user)?->getKey();

        return $companyId === null
            ? \App\Models\Product::query()->whereRaw('1 = 0')
            : \App\Models\Product::query()->where('company_id', $companyId);
    }

    /* ---------------------------------------------------------------- stats */

    /**
     * @return list<array{key: string, label: string, value: int, hint: ?string, delta: null, icon: string}>
     */
    public function stats(User $user): array
    {
        $openRfqs = (clone $this->routings($user))
            ->whereIn('status', [RfqCompanyStatus::Sent->value, RfqCompanyStatus::Viewed->value])
            ->count();

        $quotesAwaiting = (clone $this->quotes($user))
            ->whereIn('status', [QuoteStatus::Submitted->value, QuoteStatus::Viewed->value])
            ->count();

        $activeOrders = (clone $this->orders($user))
            ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
            ->count();

        $activeProducts = (clone $this->products($user))
            ->where('status', ProductStatus::Active->value)
            ->count();

        $company = $this->company($user);

        return [
            [
                'key' => 'open_rfqs',
                'label' => __('messages.account.stat_active_requests'),
                'value' => $openRfqs,
                'hint' => null,
                'delta' => null,
                'icon' => 'document-text',
            ],
            [
                'key' => 'quotes_awaiting',
                'label' => __('messages.account.stat_quotes_to_review'),
                'value' => $quotesAwaiting,
                'hint' => null,
                'delta' => null,
                'icon' => 'tag',
            ],
            [
                'key' => 'active_orders',
                'label' => __('messages.account.stat_active_orders'),
                'value' => $activeOrders,
                'hint' => null,
                'delta' => null,
                'icon' => 'clipboard-document-check',
            ],
            [
                'key' => 'active_products',
                'label' => 'Active products',
                'value' => $activeProducts,
                'hint' => null,
                'delta' => null,
                'icon' => 'cube',
            ],
            [
                'key' => 'profile_completion',
                'label' => 'Profile completion',
                'value' => (int) ($company?->profile_completion ?? 0),
                'hint' => null,
                'delta' => null,
                'icon' => 'user-group',
            ],
        ];
    }

    /* ------------------------------------------------------- preview lists */

    /** @return Collection<int, Order> */
    public function recentOrders(User $user, int $limit = self::PREVIEW): Collection
    {
        return (clone $this->orders($user))
            ->with(['rfq:id,reference_code', 'items:id,order_id,description,quantity,unit'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, Quote> */
    public function recentQuotes(User $user, int $limit = self::PREVIEW): Collection
    {
        return (clone $this->quotes($user))
            ->with(['rfq:id,reference_code,buyer_email,title', 'items'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Product counts by publication status, for a simple bar/summary card.
     * Empty array when the company has no products at all.
     *
     * @return array<string, int>
     */
    public function productsByStatus(User $user): array
    {
        $counts = (clone $this->products($user))
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(ProductStatus::cases())
            ->mapWithKeys(fn (ProductStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->filter(fn (int $count) => $count > 0)
            ->all();
    }

    /**
     * @return array{percent: int, missing: list<string>}
     */
    public function profileCompleteness(User $user): array
    {
        $company = $this->company($user);

        return [
            'percent' => (int) ($company?->profile_completion ?? 0),
            'missing' => [],
        ];
    }

    /**
     * A trail of things that really happened for this supplier — RFQ
     * routings viewed/responded and order milestone stamps — mirroring
     * BuyerDashboard::activity()'s derivation from real timestamps only.
     *
     * @return list<array{type: string, label: string, detail: string, at: string, reference: ?string, icon: string, tone: string}>
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
                'type' => 'quote_submitted',
                'label' => 'Quote submitted',
                'detail' => $quote->reference_code.' · '.($quote->rfq?->reference_code ?? ''),
                'reference' => $quote->reference_code,
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
                'in_production' => $order->production_started_at,
                'confirmed' => $order->confirmed_at,
                'awarded' => $order->awarded_at,
            ])->filter()->sortDesc();

            if ($stamp->isEmpty()) {
                continue;
            }

            $entries[] = [
                'at' => $stamp->first(),
                'type' => 'order_status_changed',
                'label' => __('messages.account.activity_order_status', [
                    'status' => mb_strtolower(__('messages.enums.order_status.'.$stamp->keys()->first())),
                ]),
                'detail' => $order->reference_code,
                'reference' => $order->reference_code,
                'icon' => 'clipboard-document-check',
                'tone' => 'forest',
            ];
        }

        usort($entries, fn (array $a, array $b) => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return collect(array_slice($entries, 0, $limit))
            ->map(fn (array $entry) => [
                'type' => $entry['type'],
                'label' => $entry['label'],
                'detail' => $entry['detail'],
                'at' => $entry['at']->toIso8601String(),
                'reference' => $entry['reference'],
                'icon' => $entry['icon'],
                'tone' => $entry['tone'],
            ])
            ->values()
            ->all();
    }

    /** True when this supplier's company has no activity at all. */
    public function isEmpty(User $user): bool
    {
        return ! $this->routings($user)->exists()
            && ! $this->quotes($user)->exists()
            && ! $this->orders($user)->exists();
    }
}
