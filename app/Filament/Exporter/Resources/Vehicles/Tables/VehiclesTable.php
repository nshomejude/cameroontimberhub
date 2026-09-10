<?php

namespace App\Filament\Exporter\Resources\Vehicles\Tables;

use App\Models\Vehicle;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VehiclesTable
{
    private const DOCUMENT_STATE = [
        'none' => ['No documents', 'gray'],
        'ok' => ['Documents ok', 'success'],
        'expiring' => ['Expiring soon', 'warning'],
        'expired' => ['Expired document', 'danger'],
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registration_number')->label('Registration')->searchable()->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->ucfirst()->toString()),
                TextColumn::make('capacity_tonnes')->label('Capacity (t)')->numeric(2)->placeholder('—'),
                IconColumn::make('is_active')->label('In service')->boolean()->sortable(),
                TextColumn::make('documents_state')
                    ->label('Compliance docs')
                    ->badge()
                    ->state(fn (Vehicle $record): string => self::DOCUMENT_STATE[$record->documentComplianceState()][0])
                    ->color(fn (Vehicle $record): string => self::DOCUMENT_STATE[$record->documentComplianceState()][1]),
                TextColumn::make('created_at')->label('Added')->date('d M Y')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('In service'),
            ])
            ->defaultSort('registration_number')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
