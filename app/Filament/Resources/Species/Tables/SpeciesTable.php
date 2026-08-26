<?php

namespace App\Filament\Resources\Species\Tables;

use App\Enums\LogExportStatus;
use App\Enums\TimberCategory;
use App\Models\Species;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                TextColumn::make('commercial_category')
                    ->label('Category')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (TimberCategory $state): string => $state->shortLabel())
                    ->color(fn (TimberCategory $state): string => $state->color())
                    ->sortable(),
                IconColumn::make('is_promoted')->boolean()->label('Promoted'),
                TextColumn::make('log_export_status')
                    ->label('Log export')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (LogExportStatus $state): string => $state->label())
                    ->color(fn (LogExportStatus $state): string => $state->color())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('density_kg_m3_min')
                    ->label('Density')
                    ->placeholder('—')
                    ->state(fn (Species $record): ?string => $record->densityRange())
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('durability_class')
                    ->label('Durability')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('typical_uses')
                    ->label('Typical uses')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state)
                    ->limit(48)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('region_availability')
                    ->label('Regions')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('companies_count')->counts('companies')->label('Companies')->badge()->sortable(),
                IconColumn::make('is_cites_listed')->boolean()->label('CITES'),
                IconColumn::make('is_published')->boolean()->label('Published'),
                TextColumn::make('updated_at')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('commercial_category')
                    ->label('Commercial category')
                    ->options(TimberCategory::options()),
                SelectFilter::make('log_export_status')
                    ->label('Log export status')
                    ->options(LogExportStatus::options()),
                TernaryFilter::make('is_promoted')->label('Promoted species'),
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
