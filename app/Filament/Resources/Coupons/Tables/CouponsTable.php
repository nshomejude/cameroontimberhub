<?php

namespace App\Filament\Resources\Coupons\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->badge(),
                TextColumn::make('type')
                    ->label('Type / value')
                    ->formatStateUsing(function ($state, $record) {
                        if ($state === 'percent') {
                            return rtrim(rtrim(number_format(((float) $record->value) * 100, 4), '0'), '.').'%';
                        }

                        return strtoupper((string) $record->currency).' '.number_format((float) $record->value, 2);
                    }),
                TextColumn::make('redemptions_count')
                    ->label('Redemptions')
                    ->formatStateUsing(fn ($state, $record) => $state.' / '.($record->max_redemptions ?? '∞')),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('valid_from')->label('From')->dateTime('d M Y')->placeholder('—'),
                TextColumn::make('valid_until')->label('Until')->dateTime('d M Y')->placeholder('—'),
                TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('code')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
