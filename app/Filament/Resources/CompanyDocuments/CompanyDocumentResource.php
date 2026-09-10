<?php

namespace App\Filament\Resources\CompanyDocuments;

use App\Filament\Resources\CompanyDocuments\Pages\ListCompanyDocuments;
use App\Filament\Resources\CompanyDocuments\Tables\CompanyDocumentsTable;
use App\Models\CompanyDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CompanyDocumentResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.documents');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.compliance');
    }
    protected static ?string $model = CompanyDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;



    protected static ?string $recordTitleAttribute = 'original_filename';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('documents.review');
    }

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

    public static function table(Table $table): Table
    {
        return CompanyDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyDocuments::route('/'),
        ];
    }
}
