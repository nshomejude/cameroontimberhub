<?php

namespace App\Filament\Resources\RegulatorySources;

use App\Filament\Resources\RegulatorySources\Pages\CreateRegulatorySource;
use App\Filament\Resources\RegulatorySources\Pages\EditRegulatorySource;
use App\Filament\Resources\RegulatorySources\Pages\ListRegulatorySources;
use App\Filament\Resources\RegulatorySources\Schemas\RegulatorySourceForm;
use App\Filament\Resources\RegulatorySources\Tables\RegulatorySourcesTable;
use App\Models\RegulatorySource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RegulatorySourceResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.regulatory_sources');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.compliance');
    }
    protected static ?string $model = RegulatorySource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;


    protected static ?string $recordTitleAttribute = 'instrument_name';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    public static function canDelete($record): bool
    {
        return (bool) auth()->user()?->can('compliance.manage');
    }

    public static function form(Schema $schema): Schema
    {
        return RegulatorySourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RegulatorySourcesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegulatorySources::route('/'),
            'create' => CreateRegulatorySource::route('/create'),
            'edit' => EditRegulatorySource::route('/{record}/edit'),
        ];
    }
}
