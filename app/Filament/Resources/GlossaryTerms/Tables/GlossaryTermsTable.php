<?php

namespace App\Filament\Resources\GlossaryTerms\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class GlossaryTermsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('term')->searchable()->sortable()->limit(60)->wrap(),
                TextColumn::make('slug')->searchable()->color('gray')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('french_term')->label('French')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('definition')->limit(80)->wrap()->toggleable(),
                IconColumn::make('is_published')->label('Published')->boolean()->sortable(),
                TextColumn::make('updated_at')->label('Updated')->date('d M Y')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_published')->label('Published'),
            ])
            ->defaultSort('term')
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
