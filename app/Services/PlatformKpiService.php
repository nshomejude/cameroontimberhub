<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Carbon;

/**
 * Blueprint §66-69 Platform Operations: North-Star KPIs computed strictly
 * from real platform data (companies, orders, listings) already recorded
 * elsewhere -- never fabricated or interpolated. Every method degrades
 * gracefully to a zero value when there is not enough real data behind it,
 * rather than throwing or dividing by zero.
 */
class PlatformKpiService
{
    /** Every company ever registered, regardless of status. */
    public function totalCompanies(): int
    {
        return Company::query()->count();
    }

    /** Companies that have completed verification. */
    public function verifiedCompaniesCount(): int
    {
        return Company::query()->where('status', CompanyStatus::Verified)->count();
    }

    /** Listings currently published on the marketplace. */
    public function activeListingsCount(): int
    {
        return Product::query()->where('status', ProductStatus::Active)->count();
    }

    /**
     * Sum of completed order totals -- the real money that changed hands.
     * Optionally narrowed to orders completed since a given date.
     */
    public function grossMerchandiseValue(?Carbon $since = null): float
    {
        return (float) Order::query()
            ->where('status', OrderStatus::Completed)
            ->when($since !== null, fn ($q) => $q->where('completed_at', '>=', $since))
            ->sum('total_amount');
    }

    /**
     * Distinct companies with any Order or Product activity (created) in the
     * trailing N months. "Activity" means the company placed/received an
     * order or published a listing in the window -- a real usage signal,
     * not a login-based definition (the platform does not track that here).
     */
    public function monthlyActiveCompanies(int $months = 1): int
    {
        $since = now()->subMonths(max(1, $months))->startOfDay();

        $fromOrders = Order::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('company_id')
            ->distinct()
            ->pluck('company_id');

        $fromProducts = Product::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('company_id')
            ->distinct()
            ->pluck('company_id');

        return $fromOrders->merge($fromProducts)->unique()->count();
    }

    /**
     * Completed orders as a share of every order that was not cancelled.
     * Returns 0.0 (not NAN/division error) when there are no eligible orders.
     */
    public function orderFulfillmentRate(): float
    {
        $eligible = Order::query()->where('status', '!=', OrderStatus::Cancelled)->count();

        if ($eligible === 0) {
            return 0.0;
        }

        $completed = Order::query()->where('status', OrderStatus::Completed)->count();

        return round($completed / $eligible, 4);
    }

    /** Average total_amount across completed orders. Returns 0.0 with none. */
    public function averageOrderValue(): float
    {
        $completed = Order::query()->where('status', OrderStatus::Completed);

        $count = (clone $completed)->count();

        if ($count === 0) {
            return 0.0;
        }

        return round((float) $completed->sum('total_amount') / $count, 2);
    }

    /**
     * The full KPI set as a flat, JSON-serialisable array -- the shape stored
     * verbatim in PlatformKpiSnapshot::metrics.
     */
    public function snapshot(): array
    {
        return [
            'total_companies' => $this->totalCompanies(),
            'verified_companies_count' => $this->verifiedCompaniesCount(),
            'active_listings_count' => $this->activeListingsCount(),
            'gross_merchandise_value' => $this->grossMerchandiseValue(),
            'monthly_active_companies' => $this->monthlyActiveCompanies(),
            'order_fulfillment_rate' => $this->orderFulfillmentRate(),
            'average_order_value' => $this->averageOrderValue(),
        ];
    }
}
