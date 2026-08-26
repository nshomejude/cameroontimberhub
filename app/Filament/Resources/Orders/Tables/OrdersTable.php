<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
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
                TextColumn::make('supplier_name')->label('Supplier')->searchable()->limit(30),
                TextColumn::make('buyer_company')->label('Buyer')
                    ->getStateUsing(fn (Order $r): string => $r->buyer_company ?: $r->buyer_name)
                    ->searchable(['buyer_company', 'buyer_name'])->limit(30),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->color()),
                TextColumn::make('total_amount')->label('Total')
                    ->formatStateUsing(fn ($state, Order $record): string => $record->money($state))
                    ->sortable(),
                TextColumn::make('payment_status')->label('Settlement')->badge()
                    ->formatStateUsing(fn (OrderPaymentStatus $state): string => $state->label())
                    ->color(fn (OrderPaymentStatus $state): string => $state->color()),
                TextColumn::make('amount_paid')->label('Recorded paid')
                    ->formatStateUsing(fn ($state, Order $record): string => $record->money($state))
                    ->toggleable(),
                TextColumn::make('receipt.receipt_number')->label('Receipt')->placeholder('—')->searchable(),
                TextColumn::make('awarded_at')->label('Awarded')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(OrderStatus::options()),
                SelectFilter::make('payment_status')->label('Settlement')->options(OrderPaymentStatus::options()),
            ])
            ->defaultSort('awarded_at', 'desc')
            ->recordActions([
                ActionGroup::make([
                    // The only way payment state ever changes. The platform has
                    // no payment integration, so this is a manual record of an
                    // off-platform settlement, made by a named staff user and
                    // written to the activity log.
                    Action::make('recordPayment')->label('Record payment')->icon('heroicon-o-banknotes')->color('success')
                        ->modalDescription('This marketplace processes no payments. Record only what you have evidence of.')
                        ->schema([
                            TextInput::make('amount_paid')->label('Amount recorded as paid')
                                ->numeric()->required()->minValue(0)
                                ->default(fn (Order $record): string => (string) $record->amount_paid)
                                ->helperText(fn (Order $record): string => 'Order total: '.$record->money($record->total_amount)),
                            TextInput::make('method')->label('Method')->maxLength(60)->placeholder('Bank transfer'),
                        ])
                        ->action(fn (Order $record, array $data) => static::run(
                            fn () => app(OrderService::class)->recordPayment($record, $data['amount_paid'], $data['method'] ?? null, auth()->user()),
                            'Settlement recorded',
                        )),

                    Action::make('voidReceipt')->label('Withdraw receipt')->icon('heroicon-o-x-circle')->color('danger')
                        ->visible(fn (Order $r): bool => $r->receipt !== null)
                        ->modalDescription('The receipt will still verify, but as VOID rather than AUTHENTIC.')
                        ->schema([TextInput::make('reason')->label('Reason')->required()->maxLength(255)])
                        ->action(function (Order $record, array $data): void {
                            $receipt = $record->receipt;

                            if (! $receipt) {
                                Notification::make()->title('No live receipt on this order')->danger()->send();

                                return;
                            }

                            $receipt->update(['voided_at' => now(), 'void_reason' => $data['reason']]);

                            activity('order')->performedOn($record)->event('receipt_voided')
                                ->causedBy(auth()->user())
                                ->withProperties(['receipt_number' => $receipt->receipt_number, 'reason' => $data['reason']])
                                ->log('Receipt withdrawn');

                            Notification::make()->title('Receipt withdrawn')->success()->send();
                        }),
                ])->label('Manage')->icon('heroicon-m-ellipsis-vertical'),
            ]);
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
