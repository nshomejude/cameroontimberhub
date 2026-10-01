<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * `GET /api/v1/supply-chain/relationships` — read-only, for the mobile
 * "Supply chain" screen.
 *
 * There is no relationship/invitation model on the platform, so nothing is
 * invented here: an ACTIVE relationship is derived purely from COMPLETED
 * orders —
 *  - `supplier`: a company the caller bought from;
 *  - `customer`: a company whose member bought from one of the caller's
 *    companies.
 * `status=pending` always returns `data: []` (no request/accept flow exists;
 * `POST` is not routed, so the app shows its "coming soon" state).
 */
class SupplyChainRelationshipController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($request->query('status', 'active') !== 'active') {
            return response()->json(['data' => []]);
        }

        $user = $request->user();
        $completed = OrderStatus::Completed->value;
        $myCompanyIds = $user->companies()->pluck('companies.id');

        // Companies the caller bought from.
        $suppliers = Order::query()
            ->where('user_id', $user->getKey())
            ->where('status', $completed)
            ->whereNotIn('company_id', $myCompanyIds)
            ->selectRaw('company_id, count(*) as orders_count, max(updated_at) as last_order_at')
            ->groupBy('company_id')
            ->get()
            ->map(fn ($row) => ['company_id' => (int) $row->company_id, 'relationship' => 'supplier', 'orders_count' => (int) $row->orders_count, 'last_order_at' => $row->last_order_at]);

        // Companies whose members bought from the caller's companies.
        $customers = $myCompanyIds->isEmpty() ? collect() : Order::query()
            ->join('company_user', 'company_user.user_id', '=', 'orders.user_id')
            ->whereIn('orders.company_id', $myCompanyIds)
            ->whereNotIn('company_user.company_id', $myCompanyIds)
            ->where('orders.status', $completed)
            ->selectRaw('company_user.company_id as company_id, count(distinct orders.id) as orders_count, max(orders.updated_at) as last_order_at')
            ->groupBy('company_user.company_id')
            ->get()
            ->map(fn ($row) => ['company_id' => (int) $row->company_id, 'relationship' => 'customer', 'orders_count' => (int) $row->orders_count, 'last_order_at' => $row->last_order_at]);

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $suppliers->concat($customers);
        $companies = Company::query()->whereIn('id', $rows->pluck('company_id')->unique())->get()->keyBy('id');

        $data = $rows
            ->filter(fn (array $row) => $companies->has($row['company_id']))
            ->sortByDesc('last_order_at')
            ->map(function (array $row) use ($companies) {
                $company = $companies[$row['company_id']];

                return [
                    'id' => "{$row['relationship']}-{$company->getKey()}",
                    'relationship' => $row['relationship'],
                    'status' => 'active',
                    'direction' => null,
                    'source' => 'completed_orders',
                    'orders_count' => $row['orders_count'],
                    'last_order_at' => $row['last_order_at'] ? \Illuminate\Support\Carbon::parse($row['last_order_at'])->toIso8601String() : null,
                    'company' => [
                        'id' => $company->getKey(),
                        'slug' => $company->slug,
                        'name' => $company->name,
                        'type' => $company->type?->value,
                    ],
                    'actions' => [],
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }
}
