<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\QuoteResource;
use App\Http\Resources\Api\V1\SupplierResource;
use App\Models\User;
use App\Services\BuyerDashboard;
use Illuminate\Http\Request;

/**
 * The buyer app's home-screen feed — the API counterpart of `/account`
 * (the web buyer dashboard partial views), reshaped for a native client.
 *
 * Every figure comes straight out of BuyerDashboard, the same service the
 * web dashboard uses — there is no second computation path. The only work
 * done here is projection: `BuyerDashboard::stats()`/`activity()` embed web
 * `route()` URLs for the Blade dashboard, and a `route()` URL is meaningless
 * (and would 404) inside a native app or a WebView, so this controller never
 * forwards those arrays verbatim. Instead it drops the `url` key and keeps
 * (or adds) a stable machine `key` for stats and a `type` + `reference` pair
 * for activity — the client already has reference-keyed endpoints
 * (`GET /rfqs/{reference}`, `/quotes/{reference}`, `/orders/{reference}`), so
 * that pair is enough to navigate without a new mapping table.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly BuyerDashboard $dashboard) {}

    public function __invoke(Request $request): array
    {
        $user = $request->user();

        $stats = collect($this->dashboard->stats($user))
            ->map(fn (array $stat) => [
                'key' => $stat['key'],
                'label' => $stat['label'],
                'value' => $stat['value'],
                'hint' => $stat['hint'],
                'delta' => $stat['delta'],
                'icon' => $stat['icon'],
            ])
            ->values()
            ->all();

        $activity = $this->activity($user);

        $ordersByStatus = $this->dashboard->ordersByStatus($user);

        return [
            'data' => [
                'stats' => $stats,
                'recent_orders' => OrderResource::collection($this->dashboard->recentOrders($user))->resolve(),
                'recent_quotes' => QuoteResource::collection($this->dashboard->recentQuotes($user))->resolve(),
                'top_suppliers' => $this->dashboard->topSuppliers($user)
                    ->map(fn (array $row) => $row['company'] ? (new SupplierResource($row['company']))->resolve() : null)
                    ->filter()
                    ->values()
                    ->all(),
                'orders_by_status' => $ordersByStatus === null ? null : [
                    'total' => $ordersByStatus['total'],
                    'slices' => collect($ordersByStatus['slices'])
                        ->map(fn (array $slice) => [
                            'status' => $slice['status']->value,
                            'label' => $slice['status']->label(),
                            'count' => $slice['count'],
                        ])
                        ->values()
                        ->all(),
                ],
                'value_trend' => $this->dashboard->valueTrend($user),
                'activity' => $activity,
            ],
        ];
    }

    /**
     * The same merge-and-sort BuyerDashboard::activity() does over the same
     * two real timestamp sources (quote submission, order milestone stamps)
     * — real computation, not a client-side reshuffle — but built here
     * directly from `recentQuotes()`/`recentOrders()` instead of
     * post-processing `activity()`'s output. `activity()` only carries a
     * translated `title`/`detail` string and a web `url`; it has no
     * structured reference field to recover one from without parsing
     * locale-dependent text, so this mirrors its logic while keeping a
     * `type` + `reference` pair the client can hand straight to the
     * existing `GET /quotes/{reference}` / `/orders/{reference}` endpoints.
     *
     * @return list<array{type: string, label: string, detail: string, at: string, reference: ?string, icon: string, tone: string}>
     */
    private function activity(User $user): array
    {
        $limit = BuyerDashboard::ACTIVITY;
        $entries = [];

        foreach ($this->dashboard->recentQuotes($user, $limit) as $quote) {
            if ($quote->submitted_at === null) {
                continue;
            }

            $entries[] = [
                'at' => $quote->submitted_at,
                'type' => 'quote_received',
                'label' => __('messages.account.activity_quote_received'),
                'detail' => __('messages.account.activity_quote_detail', [
                    'supplier' => $quote->company?->name ?? __('messages.account.activity_a_supplier'),
                    'ref' => $quote->rfq?->reference_code,
                ]),
                'reference' => $quote->reference_code,
                'icon' => 'document-text',
                'tone' => 'timber',
            ];
        }

        foreach ($this->dashboard->recentOrders($user, $limit) as $order) {
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
                'detail' => $order->reference_code.' · '.$order->supplier_name,
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
}
