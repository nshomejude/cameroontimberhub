<?php

namespace App\Filament\Resources\TimberLots;

use App\Filament\Resources\TimberLots\Pages\ListTimberLots;
use App\Filament\Resources\TimberLots\Pages\ViewTimberLot;
use App\Filament\Resources\TimberLots\RelationManagers\LotEventsRelationManager;
use App\Filament\Resources\TimberLots\Schemas\TimberLotInfolist;
use App\Filament\Resources\TimberLots\Tables\TimberLotsTable;
use App\Models\TimberLot;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Minimal read-only admin visibility into TimberLot + its Traceability
 * Event Ledger (blueprint §10). No create/edit/delete — lots and events
 * are written by the supply-chain workflow, not by admins clicking
 * around; this exists so staff can inspect a lot's hash-chained history.
 */
class TimberLotResource extends Resource
{
    protected static ?string $model = TimberLot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $recordTitleAttribute = 'lot_number';

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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return TimberLotsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TimberLotInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            LotEventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTimberLots::route('/'),
            'view' => ViewTimberLot::route('/{record}'),
        ];
    }
}
