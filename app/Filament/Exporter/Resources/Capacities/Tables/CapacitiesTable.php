<?php

namespace App\Filament\Exporter\Resources\Capacities\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CapacitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('capability')->searchable(),
                TextColumn::make('quantity')->numeric(2),
                TextColumn::make('unit'),
                TextColumn::make('period'),
                TextColumn::make('created_at')->date('d M Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
