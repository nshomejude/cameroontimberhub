<?php

namespace App\Filament\Exporter\Resources\Vehicles;

use App\Filament\Exporter\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Exporter\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Exporter\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Exporter\Resources\Vehicles\Schemas\VehicleForm;
use App\Filament\Exporter\Resources\Vehicles\Tables\VehiclesTable;
use App\Models\Vehicle;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Self-service fleet-vehicle registry for a company (gap-plan item 1.5.12).
 * Company-scoped exactly like CapacityResource; compliance paperwork rides
 * the shared polymorphic Document store (Vehicle uses HasDocuments), so
 * expiry state is a derived column, not a bespoke system.
 */
class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Fleet vehicles';

    protected static ?string $recordTitleAttribute = 'registration_number';

    protected static ?int $navigationSort = 46;

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
        $company = auth()->user()?->companies()->first();

        if (! $company) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()->where('company_id', $company->getKey());
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return VehicleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VehiclesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicles::route('/'),
            'create' => CreateVehicle::route('/create'),
            'edit' => EditVehicle::route('/{record}/edit'),
        ];
    }
}
