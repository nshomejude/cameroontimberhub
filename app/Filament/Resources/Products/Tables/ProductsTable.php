<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('company.legal_name')->label('Supplier')->searchable()->color('gray')->placeholder('—'),
                TextColumn::make('species.common_name')->label('Species')->color('gray')->placeholder('—'),
                TextColumn::make('product_type')->label('Type')->badge()
                    ->formatStateUsing(fn (ProductType $state): string => $state->label())
                    ->color(fn (ProductType $state): string => $state->color()),
                TextColumn::make('price_amount')->label('Price')->numeric(decimalPlaces: 0)->sortable()->placeholder('—'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ProductStatus $state): string => $state->label())
                    ->color(fn (ProductStatus $state): string => $state->color()),
                IconColumn::make('is_featured')->boolean()->label('Featured'),
                IconColumn::make('is_best_seller')->boolean()->label('Best seller'),
                TextColumn::make('updated_at')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ProductStatus::options()),
                SelectFilter::make('product_type')->label('Type')->options(ProductType::options()),
                SelectFilter::make('company')->relationship('company', 'legal_name')->searchable()->preload(),
                TernaryFilter::make('is_featured')->label('Featured'),
            ])
            ->defaultSort('created_at', 'desc')
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
