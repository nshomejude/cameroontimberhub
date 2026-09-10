<?php

namespace App\Filament\Exporter\Resources\CompanySpecies;

use App\Filament\Exporter\Resources\CompanySpecies\Pages\CreateCompanySpecies;
use App\Filament\Exporter\Resources\CompanySpecies\Pages\EditCompanySpecies;
use App\Filament\Exporter\Resources\CompanySpecies\Pages\ListCompanySpecies;
use App\Filament\Exporter\Resources\CompanySpecies\Schemas\CompanySpeciesForm;
use App\Filament\Exporter\Resources\CompanySpecies\Tables\CompanySpeciesTable;
use App\Models\CompanySpecies;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Self-service "species handled" catalogue for a supplier company, scoped to
 * the acting user's company exactly like CapacityResource. Saving a row with
 * a price emits a `listed` PriceObservation (docs/PRICE_DATA_STANDARD.md §5).
 */
class CompanySpeciesResource extends Resource
{
    protected static ?string $model = CompanySpecies::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $slug = 'company-species';

    protected static ?int $navigationSort = 6;

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.xnav.species_handled');
    }

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
        return CompanySpeciesForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompanySpeciesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanySpecies::route('/'),
            'create' => CreateCompanySpecies::route('/create'),
            'edit' => EditCompanySpecies::route('/{record}/edit'),
        ];
    }
}
