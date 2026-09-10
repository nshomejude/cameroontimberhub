<?php

namespace App\Filament\Exporter\Resources\Drivers\Tables;

use App\Models\Driver;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DriversTable
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
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('license_number')->label('Licence')->searchable(),
                TextColumn::make('phone')->placeholder('—'),
                IconColumn::make('is_active')->label('Driving')->boolean()->sortable(),
                TextColumn::make('documents_state')
                    ->label('Compliance docs')
                    ->badge()
                    ->state(fn (Driver $record): string => self::DOCUMENT_STATE[$record->documentComplianceState()][0])
                    ->color(fn (Driver $record): string => self::DOCUMENT_STATE[$record->documentComplianceState()][1]),
                TextColumn::make('created_at')->label('Added')->date('d M Y')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Driving'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
