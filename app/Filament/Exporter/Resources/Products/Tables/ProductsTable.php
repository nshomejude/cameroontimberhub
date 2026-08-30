<?php

namespace App\Filament\Exporter\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('product_type')->label('Type')->badge()
                    ->formatStateUsing(fn (ProductType $state): string => $state->label())
                    ->color(fn (ProductType $state): string => $state->color()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ProductStatus $state): string => $state->label())
                    ->color(fn (ProductStatus $state): string => $state->color()),
                TextColumn::make('price_amount')->label('Price')->numeric(decimalPlaces: 0)->sortable()->placeholder('—'),
                TextColumn::make('updated_at')->date('d M Y')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                Action::make('toggleStatus')
                    ->label(fn (Product $record): string => $record->status === ProductStatus::Active ? 'Unpublish' : 'Publish')
                    ->icon(fn (Product $record): string => $record->status === ProductStatus::Active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn (Product $record): string => $record->status === ProductStatus::Active ? 'gray' : 'success')
                    ->visible(fn (Product $record): bool => $record->status !== ProductStatus::Archived)
                    ->action(function (Product $record): void {
                        $record->update([
                            'status' => $record->status === ProductStatus::Active
                                ? ProductStatus::Draft
                                : ProductStatus::Active,
                        ]);

                        Notification::make()
                            ->title($record->status === ProductStatus::Active ? 'Product published' : 'Product moved back to draft')
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
