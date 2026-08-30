<?php

namespace App\Filament\Resources\Inspections;

use App\Filament\Resources\Inspections\Pages\ListInspections;
use App\Filament\Resources\Inspections\Pages\ViewInspection;
use App\Filament\Resources\Inspections\Schemas\InspectionInfolist;
use App\Filament\Resources\Inspections\Tables\InspectionsTable;
use App\Models\Inspection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-focused admin oversight of inspection reports (blueprint §27).
 * Report content is captured by the inspector in the field (out of scope
 * here) -- staff can list/view and see amendment history, not author
 * reports directly.
 */
class InspectionResource extends Resource
{
    protected static ?string $model = Inspection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Inspections';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return InspectionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InspectionsTable::configure($table);
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
            'index' => ListInspections::route('/'),
            'view' => ViewInspection::route('/{record}'),
        ];
    }
}
