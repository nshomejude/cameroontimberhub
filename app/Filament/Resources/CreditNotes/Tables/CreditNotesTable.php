<?php

namespace App\Filament\Resources\CreditNotes\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('credit_note_number')->label('Number')->searchable()->sortable(),
                TextColumn::make('invoice.invoice_number')->label('Against invoice')->searchable(),
                TextColumn::make('company.name')->label('Company')->searchable()->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn ($state, $record) => $record->currency->value.' '.number_format((float) $state, 2))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->label())
                    ->color(fn ($state) => $state->color()),
                TextColumn::make('issued_at')->label('Issued')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('issued_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
