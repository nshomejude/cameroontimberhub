<?php

namespace App\Filament\Exporter\Resources\CompanyDocuments;

use App\Filament\Exporter\Resources\CompanyDocuments\Pages\CreateCompanyDocument;
use App\Filament\Exporter\Resources\CompanyDocuments\Pages\ListCompanyDocuments;
use App\Filament\Exporter\Resources\CompanyDocuments\Schemas\CompanyDocumentForm;
use App\Filament\Exporter\Resources\CompanyDocuments\Tables\CompanyDocumentsTable;
use App\Models\CompanyDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CompanyDocumentResource extends Resource
{
    protected static ?string $model = CompanyDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Documents';

    protected static ?string $recordTitleAttribute = 'original_filename';

    protected static ?int $navigationSort = 2;

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
        return false; // documents are immutable after upload
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->when(
            $user,
            fn (Builder $query) => $query->whereHas('company', fn (Builder $c) => $c->dashboardOwned($user)),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }

    public static function form(Schema $schema): Schema
    {
        return CompanyDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CompanyDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyDocuments::route('/'),
            'create' => CreateCompanyDocument::route('/create'),
        ];
    }
}
