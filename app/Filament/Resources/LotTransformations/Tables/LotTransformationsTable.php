<?php

namespace App\Filament\Resources\LotTransformations\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LotTransformationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('processorCompany.legal_name')
                    ->label('Processor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('transformation_type')
                    ->label('Type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('input_volume_m3')
                    ->label('Input (m³)')
                    ->numeric(3)
                    ->sortable(),
                TextColumn::make('output_volume_m3')
                    ->label('Output (m³)')
                    ->numeric(3)
                    ->sortable(),
                TextColumn::make('loss_volume_m3')
                    ->label('Loss (m³)')
                    ->numeric(3)
                    ->color('danger')
                    ->sortable(),
                TextColumn::make('transformation_ratio')
                    ->label('Ratio')
                    ->numeric(4)
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('inputLots.lot_number')
                    ->label('Input lots')
                    ->badge()
                    ->separator(',')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('outputLots.lot_number')
                    ->label('Output lots')
                    ->badge()
                    ->separator(',')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('processed_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
