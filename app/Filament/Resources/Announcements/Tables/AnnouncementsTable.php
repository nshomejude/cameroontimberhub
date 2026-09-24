<?php

namespace App\Filament\Resources\Announcements\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('cta_screen')->label('CTA screen')->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('starts_at')->dateTime('d M Y H:i')->placeholder('—'),
                TextColumn::make('ends_at')->dateTime('d M Y H:i')->placeholder('—'),
                TextColumn::make('sort_order')->label('Sort')->sortable(),
                TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
