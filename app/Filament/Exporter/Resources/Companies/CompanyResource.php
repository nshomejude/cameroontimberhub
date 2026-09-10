<?php

namespace App\Filament\Exporter\Resources\Companies;

use App\Filament\Exporter\Resources\Companies\Pages\CreateCompany;
use App\Filament\Exporter\Resources\Companies\Pages\EditCompany;
use App\Filament\Exporter\Resources\Companies\Pages\ListCompanies;
use App\Filament\Exporter\Resources\Companies\Schemas\CompanyForm;
use App\Filament\Exporter\Resources\Companies\Tables\CompaniesTable;
use App\Models\Company;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CompanyResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.xnav.my_company');
    }
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;


    protected static ?string $recordTitleAttribute = 'legal_name';

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
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->when(
            $user,
            fn (Builder $query) => $query->dashboardOwned($user),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompaniesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'edit' => EditCompany::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getRecordRouteBindingEloquentQuery()->when(
            $user,
            fn (Builder $query) => $query->dashboardOwned($user),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }
}
