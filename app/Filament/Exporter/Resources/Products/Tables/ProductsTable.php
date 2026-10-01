<?php

namespace App\Filament\Exporter\Resources\Products\Tables;

use App\Domain\Catalog\ProductPublishingRules;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Filament\Exporter\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('messages.filament.product.name'))->searchable()->sortable(),
                TextColumn::make('product_type')->label(__('messages.filament.product.product_type'))->badge()
                    ->formatStateUsing(fn (ProductType $state): string => $state->label())
                    ->color(fn (ProductType $state): string => $state->color()),
                TextColumn::make('status')->label(__('messages.filament.product.status'))->badge()
                    ->formatStateUsing(fn (ProductStatus $state): string => $state->label())
                    ->color(fn (ProductStatus $state): string => $state->color()),
                TextColumn::make('price_amount')->label(__('messages.filament.product.price'))->numeric(decimalPlaces: 0)->sortable()->placeholder('—'),
                TextColumn::make('updated_at')->label(__('messages.filament.product.col_updated'))->date('d M Y')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                Action::make('toggleStatus')
                    ->label(fn (Product $record): string => $record->status === ProductStatus::Active ? __('messages.filament.product.action_unpublish') : __('messages.filament.product.action_publish'))
                    ->icon(fn (Product $record): string => $record->status === ProductStatus::Active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
                    ->color(fn (Product $record): string => $record->status === ProductStatus::Active ? 'gray' : 'success')
                    ->visible(fn (Product $record): bool => $record->status !== ProductStatus::Archived && ProductResource::canEdit($record))
                    ->action(function (Product $record): void {
                        if ($record->status === ProductStatus::Active) {
                            $record->update(['status' => ProductStatus::Draft]);

                            Notification::make()->title(__('messages.filament.product.notify_draft'))->success()->send();

                            return;
                        }

                        try {
                            $reason = ProductResource::publishBlockReason(
                                $record->company,
                                ProductPublishingRules::mergedAttributes($record, []),
                                errorPrefix: '',
                            );
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('This product is not ready to publish'))
                                ->body(implode(' ', collect($e->errors())->flatten()->all()))
                                ->send();

                            return;
                        }

                        if ($reason !== null) {
                            Notification::make()->danger()->title($reason)->send();

                            return;
                        }

                        $record->update(['status' => ProductStatus::Active]);

                        ProductResource::publishedNotification($record->company)->send();
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
