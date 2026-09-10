<?php

namespace App\Filament\Exporter\Resources\Quotes;

use App\Enums\QuoteStatus;
use App\Filament\Exporter\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Exporter\Resources\Quotes\Pages\EditQuote;
use App\Filament\Exporter\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Exporter\Resources\Quotes\Schemas\QuoteForm;
use App\Filament\Exporter\Resources\Quotes\Tables\QuotesTable;
use App\Models\Quote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QuoteResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.xnav.quotes');
    }
    protected static ?string $model = Quote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;


    protected static ?string $recordTitleAttribute = 'reference_code';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->companies()->exists();
    }

    /** Only a draft is editable — a submitted quote is the buyer's copy. */
    public static function canEdit($record): bool
    {
        return $record->status === QuoteStatus::Draft;
    }

    public static function canDelete($record): bool
    {
        return $record->status === QuoteStatus::Draft;
    }

    /**
     * Same company scoping as LeadResource: a supplier sees their own quotes
     * and nobody else's, at the query level.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()->when(
            $user,
            fn (Builder $query) => $query->whereHas('company', fn (Builder $c) => $c->dashboardOwned($user)),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return QuoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuotesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}
