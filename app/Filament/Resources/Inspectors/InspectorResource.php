<?php

namespace App\Filament\Resources\Inspectors;

use App\Filament\Resources\Inspectors\Pages\CreateInspector;
use App\Filament\Resources\Inspectors\Pages\EditInspector;
use App\Filament\Resources\Inspectors\Pages\ListInspectors;
use App\Filament\Resources\Inspectors\Schemas\InspectorForm;
use App\Filament\Resources\Inspectors\Tables\InspectorsTable;
use App\Models\Inspector;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Staff review/approval of onboarding inspectors (blueprint §26): set
 * identity_verified_at, review credentials, and change status. Full
 * inspector-facing onboarding capture is out of scope here.
 */
class InspectorResource extends Resource
{
    protected static ?string $model = Inspector::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Inspectors';

    public static function form(Schema $schema): Schema
    {
        return InspectorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InspectorsTable::configure($table);
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
            'index' => ListInspectors::route('/'),
            'create' => CreateInspector::route('/create'),
            'edit' => EditInspector::route('/{record}/edit'),
        ];
    }
}
