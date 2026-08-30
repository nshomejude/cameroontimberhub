<?php

namespace App\Filament\Resources\TimberLots\Tables;

use App\Enums\TimberLotStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TimberLotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('lot_number')->searchable()->sortable(),
                TextColumn::make('company.name')->label('Company')->searchable(),
                TextColumn::make('species.common_name')->label('Species')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TimberLotStatus $state): string => $state->label()),
                TextColumn::make('quantity')->placeholder('—'),
                TextColumn::make('lotEvents_count')->counts('lotEvents')->label('Events')->badge(),
                TextColumn::make('updated_at')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(fn () => collect(TimberLotStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
