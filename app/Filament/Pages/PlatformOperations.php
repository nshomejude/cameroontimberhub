<?php

namespace App\Filament\Pages;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Order;
use App\Services\PlatformKpiService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Blueprint §66-69 Platform Operations: the internal Ops dashboard and
 * North-Star KPI tracking, computed live from real platform data (companies,
 * orders, listings) -- never fabricated. Admin/staff only, gated on the
 * `platform-ops.view` permission.
 */
class PlatformOperations extends Page
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.pages.platform_operations');
    }

    public function getTitle(): string
    {
        return __('messages.filament.pages.platform_operations');
    }
    protected string $view = 'filament.pages.platform-operations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;



    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('platform-ops.view');
    }

    public function getKpis(): array
    {
        return app(PlatformKpiService::class)->snapshot();
    }

    /** Latest 10 orders, newest first. */
    public function getRecentOrders(): Collection
    {
        return Order::query()
            ->with('company:id,legal_name,trade_name')
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    /** Latest 10 company registrations still awaiting verification. */
    public function getPendingCompanies(): Collection
    {
        return Company::query()
            ->where('status', CompanyStatus::Pending)
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'legal_name', 'trade_name', 'status', 'created_at']);
    }
}
