<?php

namespace App\Filament\Resources\Plans\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->color('gray'),
                TextColumn::make('price_amount')->money(fn ($record) => $record->price_currency)->label('Price'),
                TextColumn::make('billing_period')->badge()->color('gray'),
                TextColumn::make('subscriptions_count')->counts('subscriptions')->label('Subscribers')->badge(),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('sort_order')->sortable()->toggleable(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
