<?php

namespace App\Filament\Resources\Pages\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->limit(60),
                TextColumn::make('slug')->color('gray')->limit(40)->copyable(),
                TextColumn::make('template')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'landing' => 'warning',
                        'legal' => 'info',
                        'programmatic' => 'success',
                        default => 'gray',
                    }),
                IconColumn::make('is_published')->boolean()->label('Published'),
                TextColumn::make('createdBy.name')->label('Author')->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('template')
                    ->options([
                        'static' => 'Static',
                        'landing' => 'Landing',
                        'programmatic' => 'Programmatic',
                        'legal' => 'Legal',
                    ]),
                TernaryFilter::make('is_published')->label('Published'),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
