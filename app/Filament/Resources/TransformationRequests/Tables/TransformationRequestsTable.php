<?php

namespace App\Filament\Resources\TransformationRequests\Tables;

use App\Enums\TransformationRequestStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransformationRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_code')
                    ->label('Reference')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('requesterCompany.legal_name')
                    ->label('Requester')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('providerCompany.legal_name')
                    ->label('Provider')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('service')
                    ->badge()
                    ->sortable(),
                TextColumn::make('volume_m3')
                    ->label('Volume (m³)')
                    ->numeric(3)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TransformationRequestStatus $state) => $state->label())
                    ->color(fn (TransformationRequestStatus $state) => $state->color())
                    ->sortable(),
                TextColumn::make('lot_transformation_id')
                    ->label('Ledger entry')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(TransformationRequestStatus::options()),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
