<?php

namespace App\Filament\Resources\LotTransformations;

use App\Filament\Resources\LotTransformations\Pages\CreateLotTransformation;
use App\Filament\Resources\LotTransformations\Pages\EditLotTransformation;
use App\Filament\Resources\LotTransformations\Pages\ListLotTransformations;
use App\Filament\Resources\LotTransformations\Schemas\LotTransformationForm;
use App\Filament\Resources\LotTransformations\Tables\LotTransformationsTable;
use App\Models\LotTransformation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Minimal admin visibility for the mass-balance ledger (blueprint §11):
 * "what did this output lot actually come from, and how much was lost?"
 * Read-focused — a simple staff form exists for recording transformations,
 * but this is not a public self-service flow.
 */
class LotTransformationResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.lot_transformations');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.catalogue');
    }
    protected static ?string $model = LotTransformation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;



    protected static ?int $navigationSort = 2;

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
        return auth()->user()?->can('products.manage') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return LotTransformationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LotTransformationsTable::configure($table);
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
            'index' => ListLotTransformations::route('/'),
            'create' => CreateLotTransformation::route('/create'),
            'edit' => EditLotTransformation::route('/{record}/edit'),
        ];
    }
}
