<?php

namespace App\Filament\Exporter\Resources\InspectionRequests\Tables;

use Filament\Actions\CreateAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InspectionRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('timberLot.lot_number')
                    ->label('Lot')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('inspection_type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('scheduled_for')
                    ->label('Preferred date')
                    ->date()
                    ->sortable(),
                TextColumn::make('inspector.user.name')
                    ->label('Inspector')
                    ->placeholder('Awaiting staff assignment')
                    ->badge(fn ($state): bool => $state === null)
                    ->color(fn ($state): string => $state === null ? 'warning' : 'success'),
                IconColumn::make('finalised_at')
                    ->label('Report finalised')
                    ->boolean()
                    ->state(fn ($record): bool => $record->finalised_at !== null),
                TextColumn::make('result')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->emptyStateHeading('No inspection requests yet')
            ->emptyStateDescription('Request an inspection for one of your timber lots to get started.')
            ->emptyStateActions([
                CreateAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
