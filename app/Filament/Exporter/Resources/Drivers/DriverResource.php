<?php

namespace App\Filament\Exporter\Resources\Drivers;

use App\Filament\Exporter\Resources\Drivers\Pages\CreateDriver;
use App\Filament\Exporter\Resources\Drivers\Pages\EditDriver;
use App\Filament\Exporter\Resources\Drivers\Pages\ListDrivers;
use App\Filament\Exporter\Resources\Drivers\Schemas\DriverForm;
use App\Filament\Exporter\Resources\Drivers\Tables\DriversTable;
use App\Models\Driver;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Self-service fleet-driver registry for a company (gap-plan item 1.5.12).
 * Company-scoped exactly like VehicleResource; driving licences and medical
 * certificates ride the shared polymorphic Document store (Driver uses
 * HasDocuments), so expiry state is a derived column.
 */
class DriverResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.xnav.fleet_drivers');
    }
    protected static ?string $model = Driver::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;


    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 47;

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
        return DriverForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DriversTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDrivers::route('/'),
            'create' => CreateDriver::route('/create'),
            'edit' => EditDriver::route('/{record}/edit'),
        ];
    }
}
