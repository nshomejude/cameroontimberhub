<?php

namespace App\Filament\Exporter\Resources\CarbonProjects;

use App\Enums\OrganisationType;
use App\Filament\Exporter\Resources\CarbonProjects\Pages\CreateCarbonProject;
use App\Filament\Exporter\Resources\CarbonProjects\Pages\EditCarbonProject;
use App\Filament\Exporter\Resources\CarbonProjects\Pages\ListCarbonProjects;
use App\Filament\Exporter\Resources\CarbonProjects\Schemas\CarbonProjectForm;
use App\Filament\Exporter\Resources\CarbonProjects\Tables\CarbonProjectsTable;
use App\Models\CarbonProject;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Self-service carbon project listing, gated to companies of
 * OrganisationType::CarbonDeveloper. Unlike Capacity (a generic primitive
 * several company types already share), a carbon project is a distinct
 * domain concept -- reforestation/avoided-deforestation project data --
 * that only makes sense for a carbon-developer company, so this resource
 * is deliberately restricted rather than opened to every company type.
 */
class CarbonProjectResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.xnav.carbon_projects');
    }
    protected static ?string $model = CarbonProject::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;


    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 6;

    private static function currentCompanyIsCarbonDeveloper(): bool
    {
        $company = auth()->user()?->companies()->first();

        return $company !== null && $company->type === OrganisationType::CarbonDeveloper;
    }

    public static function canViewAny(): bool
    {
        return self::currentCompanyIsCarbonDeveloper();
    }

    public static function canCreate(): bool
    {
        return self::currentCompanyIsCarbonDeveloper();
    }

    public static function canEdit($record): bool
    {
        return self::currentCompanyIsCarbonDeveloper();
    }

    public static function canDelete($record): bool
    {
        return self::currentCompanyIsCarbonDeveloper();
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $company = $user?->companies()->first();

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
        return CarbonProjectForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CarbonProjectsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCarbonProjects::route('/'),
            'create' => CreateCarbonProject::route('/create'),
            'edit' => EditCarbonProject::route('/{record}/edit'),
        ];
    }
}
