<?php

namespace App\Filament\Exporter\Resources\TransformationRequests\Tables;

use App\Enums\TransformationRequestStatus;
use App\Filament\Exporter\Resources\TransformationRequests\Actions\TransformationRequestActions;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
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
                    ->searchable(),
                TextColumn::make('providerCompany.legal_name')
                    ->label('Provider')
                    ->searchable(),
                TextColumn::make('service')
                    ->badge(),
                TextColumn::make('volume_m3')
                    ->label('Volume (m³)')
                    ->numeric(3)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TransformationRequestStatus $state) => $state->label())
                    ->color(fn (TransformationRequestStatus $state) => $state->color()),
                TextColumn::make('quote_amount')
                    ->label('Quote')
                    ->formatStateUsing(fn ($state, $record) => $state === null ? null : number_format((float) $state, 2).' '.$record->quote_currency)
                    ->placeholder('—'),
                TextColumn::make('deadline')
                    ->date()
                    ->placeholder('—'),
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
            ->recordActions([
                ViewAction::make(),
                ActionGroup::make(TransformationRequestActions::all()),
            ])
            ->emptyStateHeading('No transformation requests yet');
    }
}
