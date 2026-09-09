<?php

namespace App\Filament\Resources\WebhookDeliveries\Tables;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class WebhookDeliveriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subscription.company.name')->label('Company'),
                TextColumn::make('subscription.url')->label('URL')->limit(40),
                TextColumn::make('event_type')->badge()->color('gray'),
                TextColumn::make('response_code')->label('Response')->placeholder('—'),
                TextColumn::make('attempt'),
                IconColumn::make('delivered_at')->label('Delivered')->boolean()->getStateUsing(fn (WebhookDelivery $record) => $record->isDelivered()),
                IconColumn::make('failed_permanently_at')->label('Dead')->boolean()->color('danger')->getStateUsing(fn (WebhookDelivery $record) => $record->isFailedPermanently()),
                TextColumn::make('created_at')->label('Created')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('event_type')
                    ->options(fn () => WebhookDelivery::query()->distinct()->pluck('event_type', 'event_type')->all()),
                TernaryFilter::make('delivered')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('delivered_at'),
                        false: fn ($query) => $query->whereNull('delivered_at'),
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('retryNow')
                    ->label('Retry now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (WebhookDelivery $record): bool => $record->isFailedPermanently() && $record->subscription?->is_active)
                    ->action(function (WebhookDelivery $record): void {
                        $record->update(['failed_permanently_at' => null]);

                        DeliverWebhookJob::dispatch(
                            $record->subscription_id,
                            $record->event_type,
                            $record->payload,
                            1,
                            $record->id,
                        );

                        Notification::make()->title('Retry dispatched')->success()->send();
                    }),
            ]);
    }
}
