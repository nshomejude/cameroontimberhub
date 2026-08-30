<?php

namespace App\Filament\Resources\Inspections\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InspectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('inspector.user.name')
                    ->label('Inspector')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('timberLot.lot_number')
                    ->label('Lot')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('inspection_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('scheduled_for')
                    ->date()
                    ->sortable(),
                TextColumn::make('performed_at')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('result')
                    ->badge()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('finalised_at')
                    ->label('Finalised')
                    ->dateTime('d M Y H:i')
                    ->placeholder('No')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('inspection_type')
                    ->options([
                        'pre_production' => 'Pre-production',
                        'pre_shipment' => 'Pre-shipment',
                        'loading' => 'Loading',
                        'quantity' => 'Quantity',
                        'moisture' => 'Moisture',
                        'species_identification' => 'Species identification',
                        'quality_grade' => 'Quality/grade',
                        'packaging' => 'Packaging',
                        'document_cross_check' => 'Document cross-check',
                        'site' => 'Site',
                    ]),
                SelectFilter::make('result')
                    ->options([
                        'pass' => 'Pass',
                        'fail' => 'Fail',
                        'conditional' => 'Conditional',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
