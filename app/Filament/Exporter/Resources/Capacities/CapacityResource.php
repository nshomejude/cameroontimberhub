<?php

namespace App\Filament\Exporter\Resources\Capacities;

use App\Filament\Exporter\Resources\Capacities\Pages\CreateCapacity;
use App\Filament\Exporter\Resources\Capacities\Pages\EditCapacity;
use App\Filament\Exporter\Resources\Capacities\Pages\ListCapacities;
use App\Filament\Exporter\Resources\Capacities\Schemas\CapacityForm;
use App\Filament\Exporter\Resources\Capacities\Tables\CapacitiesTable;
use App\Models\Capacity;
use App\Models\Company;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Self-service haulage/transport capacity listing for logistics companies,
 * built on the existing polymorphic App\Models\Capacity (owner_type/owner_id)
 * rather than a new model. canViewAny() is left open to any company type --
 * a manufacturer or processor already lists production capacity here (see
 * TransformationNetworkController's capability search), so gating this to
 * OrganisationType::Logistics only would take away a surface those company
 * types already rely on.
 */
class CapacityResource extends Resource
{
    protected static ?string $model = Capacity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Capacity';

    protected static ?string $recordTitleAttribute = 'capability';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $company = $user?->companies()->first();

        if (! $company) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('owner_type', Company::class)
            ->where('owner_id', $company->getKey());
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return CapacityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CapacitiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCapacities::route('/'),
            'create' => CreateCapacity::route('/create'),
            'edit' => EditCapacity::route('/{record}/edit'),
        ];
    }
}
