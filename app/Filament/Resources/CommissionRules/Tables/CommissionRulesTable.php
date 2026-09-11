<?php

namespace App\Filament\Resources\CommissionRules\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommissionRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('segment')->placeholder('All')->badge()->color('gray'),
                TextColumn::make('plan_tier')->label('Tier')->placeholder('All')->badge()->color('gray'),
                TextColumn::make('domestic_rate')
                    ->label('Domestic')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format(((float) $state) * 100, 4), '0'), '.').'%'),
                TextColumn::make('international_rate')
                    ->label('International')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format(((float) $state) * 100, 4), '0'), '.').'%'),
                TextColumn::make('cap_amount')->label('Cap amount')->placeholder('—')->numeric(2),
                TextColumn::make('cap_percent')
                    ->label('Cap %')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : rtrim(rtrim(number_format(((float) $state) * 100, 4), '0'), '.').'%'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('effective_from')->date('d M Y')->placeholder('—'),
                TextColumn::make('effective_until')->date('d M Y')->placeholder('—'),
                TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
