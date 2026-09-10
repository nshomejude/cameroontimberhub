<?php

namespace App\Filament\Pages;

use App\Services\MarketIntelligenceService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Blueprint §33-34 Market Intelligence dashboard: the Price Index, Demand
 * Index and Supplier Performance Index, computed live from real order/RFQ
 * data (never fabricated). Admin/staff only, gated on the
 * `market-intelligence.view` permission.
 */
class MarketIntelligence extends Page
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.pages.market_intelligence');
    }

    public function getTitle(): string
    {
        return __('messages.filament.pages.market_intelligence');
    }
    protected string $view = 'filament.pages.market-intelligence';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;



    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('market-intelligence.view');
    }

    public function getPriceIndex(): Collection
    {
        return app(MarketIntelligenceService::class)->priceIndexBySpecies();
    }

    public function getDemandIndex(): Collection
    {
        return app(MarketIntelligenceService::class)->demandIndex();
    }

    public function getSupplierPerformanceIndex(): Collection
    {
        return app(MarketIntelligenceService::class)->supplierPerformanceIndex();
    }
}
