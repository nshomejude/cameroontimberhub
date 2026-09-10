<?php

namespace App\Filament\Resources\TaxRules\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TaxRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('jurisdiction')->badge()->sortable(),
                TextColumn::make('rate')
                    ->label('Rate')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format(((float) $state) * 100, 4), '0'), '.').'%'),
                TextColumn::make('applies_to')->label('Segment')->placeholder('All')->badge()->color('gray'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('effective_from')->date('d M Y')->placeholder('—'),
                TextColumn::make('effective_until')->date('d M Y')->placeholder('—'),
                TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('jurisdiction')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
