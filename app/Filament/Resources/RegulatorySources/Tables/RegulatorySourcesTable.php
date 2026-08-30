<?php

namespace App\Filament\Resources\RegulatorySources\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RegulatorySourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('authority')->searchable()->sortable(),
                TextColumn::make('instrument_name')->searchable()->sortable(),
                TextColumn::make('jurisdiction')->badge()->color('gray'),
                TextColumn::make('legal_review_status')->badge(),
                TextColumn::make('next_review_date')->date()->sortable(),
            ])
            ->defaultSort('authority')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
