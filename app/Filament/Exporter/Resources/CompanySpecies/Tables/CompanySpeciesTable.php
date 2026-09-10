<?php

namespace App\Filament\Exporter\Resources\CompanySpecies\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompanySpeciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('species.common_name')->label('Species')->searchable()->sortable(),
                TextColumn::make('unit'),
                TextColumn::make('region'),
                TextColumn::make('updated_at')->dateTime('d M Y')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
