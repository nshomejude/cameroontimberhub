<?php

namespace App\Filament\Exporter\Resources\Orders\Tables;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_code')->label('Order')->searchable()->sortable(),
                TextColumn::make('rfq.reference_code')->label('Request')->searchable(),
                // The snapshot, not a join — the buyer's profile may have moved on.
                TextColumn::make('buyer_company')->label('Buyer')
                    ->getStateUsing(fn (Order $r): string => $r->buyer_company ?: $r->buyer_name)
                    ->searchable(['buyer_company', 'buyer_name']),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),
                TextColumn::make('total_amount')->label('Total')
                    ->formatStateUsing(fn ($state, Order $record): string => $record->money($state))
                    ->sortable(),
                TextColumn::make('payment_status')->label('Settlement')->badge()
                    ->formatStateUsing(fn (OrderPaymentStatus $state): string => $state->label())
                    ->color(fn (OrderPaymentStatus $state): string => $state->color())
                    ->tooltip('Recorded by platform staff from off-platform evidence. This marketplace processes no payments.'),
                TextColumn::make('items_count')->counts('items')->label('Lines'),
                TextColumn::make('awarded_at')->label('Awarded')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('expected_delivery_at')->label('Expected delivery')->date('d M Y')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrderStatus::options()),
                SelectFilter::make('payment_status')->label('Settlement')->options(OrderPaymentStatus::options()),
            ])
            ->defaultSort('awarded_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    static::step('confirm', 'Confirm order', 'heroicon-o-check-circle', 'success', OrderStatus::Confirmed),
                    static::step('startProduction', 'Start production', 'heroicon-o-cog-6-tooth', 'warning', OrderStatus::InProduction),
                    static::step('ship', 'Mark shipped', 'heroicon-o-truck', 'warning', OrderStatus::Shipped),
                    static::step('deliver', 'Mark delivered', 'heroicon-o-inbox-arrow-down', 'success', OrderStatus::Delivered),
                    static::step('complete', 'Complete order', 'heroicon-o-archive-box', 'success', OrderStatus::Completed),

                    Action::make('cancel')->label('Cancel order')->icon('heroicon-o-x-circle')->color('danger')
                        ->visible(fn (Order $r): bool => static::allows($r, OrderStatus::Cancelled))
                        ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(500)])
                        ->action(fn (Order $record, array $data) => static::run(
                            fn () => app(OrderService::class)->cancel($record, $data['reason'], auth()->user()),
                            'Order cancelled',
                        )),
                ])->label('Update')->icon('heroicon-m-ellipsis-vertical'),
            ]);
    }

    /**
     * One lifecycle step. Visibility is driven by OrderService::TRANSITIONS, so
     * the UI can never offer a move the state machine would reject.
     */
    protected static function step(string $method, string $label, string $icon, string $color, OrderStatus $to): Action
    {
        return Action::make($method)->label($label)->icon($icon)->color($color)->requiresConfirmation()
            ->visible(fn (Order $r): bool => static::allows($r, $to))
            ->action(fn (Order $record) => static::run(
                fn () => app(OrderService::class)->{$method}($record, auth()->user()),
                $label.' — done',
            ));
    }

    protected static function allows(Order $order, OrderStatus $to): bool
    {
        return in_array($to->value, OrderService::TRANSITIONS[$order->status->value] ?? [], true);
    }

    protected static function run(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($message)->success()->send();
    }
}
