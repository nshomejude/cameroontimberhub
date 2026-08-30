<?php

namespace App\Filament\Exporter\Resources\CarbonProjects\Tables;

use App\Enums\ProductStatus;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CarbonProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('project_type')->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ProductStatus $state): string => $state->label())
                    ->color(fn (ProductStatus $state): string => $state->color()),
                TextColumn::make('region')->placeholder('—'),
                TextColumn::make('area_hectares')->label('Hectares')->numeric(2)->placeholder('—'),
                TextColumn::make('created_at')->date('d M Y')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
