<?php

namespace App\Filament\Resources\Species;

use App\Filament\Resources\Species\Pages\CreateSpecies;
use App\Filament\Resources\Species\Pages\EditSpecies;
use App\Filament\Resources\Species\Pages\ListSpecies;
use App\Filament\Resources\Species\Schemas\SpeciesForm;
use App\Filament\Resources\Species\Tables\SpeciesTable;
use App\Models\Species;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SpeciesResource extends Resource
{
    protected static ?string $model = Species::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $recordTitleAttribute = 'common_name';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return static::hasPermission();
    }

    public static function canCreate(): bool
    {
        return static::hasPermission();
    }

    public static function canEdit($record): bool
    {
        return static::hasPermission();
    }

    public static function canDelete($record): bool
    {
        return static::hasPermission();
    }

    public static function canDeleteAny(): bool
    {
        return static::hasPermission();
    }

    protected static function hasPermission(): bool
    {
        return auth()->user()?->can('species.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return SpeciesForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SpeciesTable::configure($table);
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
            'index' => ListSpecies::route('/'),
            'create' => CreateSpecies::route('/create'),
            'edit' => EditSpecies::route('/{record}/edit'),
        ];
    }
}
