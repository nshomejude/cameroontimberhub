<?php

namespace App\Filament\Resources\GlossaryTerms;

use App\Filament\Resources\GlossaryTerms\Pages\CreateGlossaryTerm;
use App\Filament\Resources\GlossaryTerms\Pages\EditGlossaryTerm;
use App\Filament\Resources\GlossaryTerms\Pages\ListGlossaryTerms;
use App\Filament\Resources\GlossaryTerms\Schemas\GlossaryTermForm;
use App\Filament\Resources\GlossaryTerms\Tables\GlossaryTermsTable;
use App\Models\GlossaryTerm;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GlossaryTermResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.glossary_terms');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.content');
    }
    protected static ?string $model = GlossaryTerm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;


    protected static ?string $recordTitleAttribute = 'term';


    protected static ?int $navigationSort = 2;

    /**
     * Glossary entries are editorial content, governed by the same permission
     * as the CMS pages and insights articles — `pages.manage`.
     */
    protected static function hasPermission(): bool
    {
        return auth()->user()?->can('pages.manage') ?? false;
    }

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

    public static function form(Schema $schema): Schema
    {
        return GlossaryTermForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GlossaryTermsTable::configure($table);
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
            'index' => ListGlossaryTerms::route('/'),
            'create' => CreateGlossaryTerm::route('/create'),
            'edit' => EditGlossaryTerm::route('/{record}/edit'),
        ];
    }
}
