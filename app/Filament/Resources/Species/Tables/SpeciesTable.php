<?php

namespace App\Filament\Resources\Species\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SpeciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('common_name')->searchable()->sortable(),
                TextColumn::make('scientific_name')->searchable()->color('gray')->placeholder('—'),
                TextColumn::make('companies_count')->counts('companies')->label('Companies')->badge()->sortable(),
                IconColumn::make('is_cites_listed')->boolean()->label('CITES'),
                IconColumn::make('is_published')->boolean()->label('Published'),
                TextColumn::make('updated_at')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_published')->label('Published'),
                TernaryFilter::make('is_cites_listed')->label('CITES listed'),
            ])
            ->defaultSort('common_name')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
