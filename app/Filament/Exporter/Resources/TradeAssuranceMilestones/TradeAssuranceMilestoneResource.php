<?php

namespace App\Filament\Exporter\Resources\TradeAssuranceMilestones;

use App\Filament\Exporter\Resources\TradeAssuranceMilestones\Pages\ListTradeAssuranceMilestones;
use App\Filament\Exporter\Resources\TradeAssuranceMilestones\Tables\TradeAssuranceMilestonesTable;
use App\Models\TradeAssuranceMilestone;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Self-service Trade Assurance view for suppliers (blueprint §28, Phase 1).
 *
 * Coordination/tracking only — NOT escrow or fund custody. A supplier can see
 * the milestones agreed for their own orders and mark one "In progress"; only
 * the buyer can confirm a milestone (done from the buyer-facing order page,
 * never from here). Read+limited-update, mirroring the philosophy of
 * InspectionRequestResource: the parts of the lifecycle that belong to the
 * other party are simply not offered as actions in this panel.
 */
class TradeAssuranceMilestoneResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.xnav.trade_assurance');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.trade_assurance_milestone_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.trade_assurance_milestone_many');
    }
    protected static ?string $model = TradeAssuranceMilestone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;




    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * Scoped to milestones whose agreement's order belongs to the current
     * user's company — a supplier can never see (or route to) another
     * company's Trade Assurance milestones.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $company = $user?->companies()->first();

        if (! $company) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->whereHas('agreement.order', function (Builder $query) use ($company): void {
                $query->where('company_id', $company->getKey());
            });
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function table(Table $table): Table
    {
        return TradeAssuranceMilestonesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTradeAssuranceMilestones::route('/'),
        ];
    }
}
