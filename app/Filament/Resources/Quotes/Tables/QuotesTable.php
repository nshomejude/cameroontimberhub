<?php

namespace App\Filament\Resources\Quotes\Tables;

use App\Enums\QuoteStatus;
use App\Models\Quote;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QuotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_code')->label('Quote')->searchable(),
                TextColumn::make('rfq.reference_code')->label('Request')->searchable(),
                TextColumn::make('company.legal_name')->label('Supplier')->searchable()->limit(30),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (QuoteStatus $state): string => $state->label())
                    ->color(fn (QuoteStatus $state): string => $state->color()),
                TextColumn::make('total_amount')->label('Total')
                    ->formatStateUsing(fn ($state, Quote $record): string => $record->money($state))
                    ->sortable(),
                TextColumn::make('items_count')->counts('items')->label('Lines'),
                TextColumn::make('valid_until')->label('Valid until')->date('d M Y')->placeholder('—')->sortable(),
                TextColumn::make('submitted_at')->label('Submitted')->dateTime('d M Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('decided_at')->label('Decided')->dateTime('d M Y H:i')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(QuoteStatus::options()),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
