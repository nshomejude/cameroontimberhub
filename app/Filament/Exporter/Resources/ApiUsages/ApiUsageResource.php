<?php

namespace App\Filament\Exporter\Resources\ApiUsages;

use App\Filament\Exporter\Resources\ApiUsages\Pages\ListApiUsages;
use App\Filament\Exporter\Resources\ApiUsages\Tables\ApiUsagesTable;
use App\Models\ApiKeyMeta;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only, company-scoped view of the signed-in company's own `/api/v1`
 * usage (architecture plan §4 — "API as a product": a company paying for or
 * evaluating API access should be able to see how much it's actually
 * using). Mirrors WebhookSubscriptionResource's company-scoping pattern
 * (Company::scopeDashboardOwned()) — a company can only ever see its own
 * keys' usage, never another company's.
 */
class ApiUsageResource extends Resource
{
    protected static ?string $model = ApiKeyMeta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'API usage';

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

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->when(
            $user,
            fn (Builder $query) => $query->whereHas('company', fn (Builder $c) => $c->dashboardOwned($user)),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function table(Table $table): Table
    {
        return ApiUsagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiUsages::route('/'),
        ];
    }
}
