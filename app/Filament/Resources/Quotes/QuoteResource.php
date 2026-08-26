<?php

namespace App\Filament\Resources\Quotes;

use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\Tables\QuotesTable;
use App\Models\Quote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Admin oversight of supplier quotes. Strictly read-only: the quote belongs to
 * the supplier who wrote it and the buyer who received it, and staff have no
 * business editing either side's copy.
 */
class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|\UnitEnum|null $navigationGroup = 'Leads';

    protected static ?string $navigationLabel = 'Quotes';

    protected static ?string $recordTitleAttribute = 'reference_code';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('rfqs.triage');
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
        return QuotesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotes::route('/'),
        ];
    }
}
