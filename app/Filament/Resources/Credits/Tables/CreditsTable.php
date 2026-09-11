<?php

namespace App\Filament\Resources\Credits\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CreditsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.name')->label('Company')->searchable()->sortable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => ((float) $state) >= 0 ? '+'.number_format((float) $state, 2) : number_format((float) $state, 2))
                    ->color(fn ($state) => ((float) $state) >= 0 ? 'success' : 'danger'),
                TextColumn::make('currency'),
                TextColumn::make('reason')->limit(50),
                TextColumn::make('source')->badge(),
                TextColumn::make('grantedBy.name')->label('Granted by')->placeholder('—'),
                TextColumn::make('created_at')->label('Date')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
