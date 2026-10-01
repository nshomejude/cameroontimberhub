<?php

namespace App\Filament\Exporter\Resources\Orders\Tables;

use App\Domain\Trade\Commands\RecordOrderDeliveryCommand;
use App\Domain\Trade\Commands\RecordOrderShipmentCommand;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\ShipmentService;
use App\Services\ShipmentWaybillQrCodeService;
use App\Support\Bus\CommandBus;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
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
                // Phase 3 shipment facts. Supplier-entered, no carrier feed
                // behind them, so both are toggleable and show a dash when the
                // supplier has not filled them in.
                TextColumn::make('carrier')->label('Carrier')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tracking_number')->label('Tracking no.')->placeholder('—')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('documents_count')->counts('documents')->label('Docs')->toggleable(isToggledHiddenByDefault: true),
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
                    static::shipAction(),
                    static::deliverAction(),

                    /*
                     * "Complete order" is deliberately NOT offered here.
                     *
                     * Phase 3 makes completion the BUYER's act — it is their
                     * confirmation that the goods arrived acceptably, and it is
                     * what makes them eligible to review this supplier. A
                     * supplier who could close their own order could manufacture
                     * that eligibility, so the button is gone from the supplier
                     * panel and OrderLifecycleService::complete() refuses the
                     * supplier regardless. Platform staff retain the move in the
                     * admin panel for genuine intervention.
                     */

                    /*
                     * Shipment details. There is no carrier integration behind
                     * any of this: every field is typed by a human here, and the
                     * buyer's card renders only the ones that are non-empty.
                     */
                    Action::make('shipment')->label('Shipment details')->icon('heroicon-o-truck')->color('info')
                        ->visible(fn (Order $r): bool => ! $r->status->isTerminal())
                        ->fillForm(fn (Order $r): array => $r->only([
                            'carrier', 'tracking_number', 'tracking_url', 'shipping_method',
                            'vessel_name', 'voyage_number', 'container_number',
                            'port_of_loading', 'port_of_discharge',
                        ]) + [
                            'etd' => $r->etd?->toDateString(),
                            'eta' => $r->eta?->toDateString(),
                        ])
                        ->schema([
                            TextInput::make('carrier')->maxLength(120),
                            TextInput::make('tracking_number')->label('Tracking number')->maxLength(120),
                            TextInput::make('tracking_url')->label('Carrier tracking link')->url()->maxLength(500)
                                ->helperText('Must be an http(s) address. It is shown to the buyer as an outbound link to the carrier.'),
                            TextInput::make('shipping_method')->maxLength(120),
                            TextInput::make('vessel_name')->label('Vessel')->maxLength(120),
                            TextInput::make('voyage_number')->label('Voyage')->maxLength(60),
                            TextInput::make('container_number')->label('Container')->maxLength(60),
                            TextInput::make('port_of_loading')->maxLength(120),
                            TextInput::make('port_of_discharge')->maxLength(120),
                            DatePicker::make('etd')->label('Departed'),
                            DatePicker::make('eta')->label('Estimated arrival'),
                        ])
                        ->action(fn (Order $record, array $data) => static::run(function () use ($record, $data) {
                            $record->forceFill(array_map(
                                fn ($v) => ($v === '' || $v === null) ? null : $v,
                                $data,
                            ))->save();

                            activity('order')->performedOn($record)->causedBy(auth()->user())
                                ->event('tracking_updated')
                                ->log('Supplier updated the shipment details');
                        }, 'Shipment details saved')),

                    static::createShipmentAction(),

                    /*
                     * Record an OFF-PLATFORM payment.
                     *
                     * ⚠ This marketplace processes no payments. The form takes
                     * an amount and a free-text method name and nothing else —
                     * no card, bank or account details are collected anywhere,
                     * because there is no payment system to hand them to.
                     * OrderService::recordPayment() owns the arithmetic, the
                     * status derivation and the audit entry.
                     */
                    Action::make('recordPayment')->label('Record a payment')->icon('heroicon-o-banknotes')->color('success')
                        ->visible(fn (Order $r): bool => $r->status !== OrderStatus::Cancelled)
                        ->schema([
                            TextInput::make('amount')->label('Total received to date')->numeric()
                                ->required()->minValue(0)
                                ->helperText('The cumulative amount that has actually reached you, in the order currency.'),
                            TextInput::make('method')->label('How it arrived')->maxLength(80)
                                ->placeholder('e.g. Bank transfer')
                                ->helperText('A name only. Never enter account numbers or any payment credential.'),
                        ])
                        ->action(fn (Order $record, array $data) => static::run(
                            fn () => app(OrderService::class)->recordPayment($record, $data['amount'], $data['method'] ?? null, auth()->user()),
                            'Payment recorded',
                        )),

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

    /**
     * "Mark shipped" — routed through the CommandBus (architecture plan
     * Task 0.1/Phase 1) instead of calling OrderService::ship() directly, so
     * the OrderShipped domain event is recorded to the outbox in the same
     * transaction as the state change. Byte-identical behaviour otherwise:
     * same visibility rule, same confirmation prompt, same notification.
     */
    protected static function shipAction(): Action
    {
        return Action::make('ship')->label('Mark shipped')->icon('heroicon-o-truck')->color('warning')
            ->requiresConfirmation()
            ->visible(fn (Order $r): bool => static::allows($r, OrderStatus::Shipped))
            ->action(fn (Order $record) => static::run(
                fn () => app(CommandBus::class)->dispatch(new RecordOrderShipmentCommand(
                    orderId: $record->getKey(),
                    actingUserId: auth()->id(),
                )),
                'Mark shipped — done',
            ));
    }

    /**
     * "Create shipment / waybill" — the trackable, waybill-bearing Shipment
     * (distinct from "Shipment details" above, which only edits free-text
     * carrier facts on the order). Vehicle/driver are optional and come from
     * the supplier's own fleet or a logistics partner's
     * (ShipmentService::selectableVehicles()/Drivers()); the carrier company
     * is derived from them. Marking the order shipped auto-creates a bare
     * shipment if none exists, so this is for assigning fleet up front or
     * for split consignments.
     */
    protected static function createShipmentAction(): Action
    {
        $fleetLabel = fn (string $name, $model): string => "{$name} ({$model->company?->name})";

        return Action::make('createShipment')->label('Create shipment / waybill')->icon('heroicon-o-qr-code')->color('primary')
            ->visible(fn (Order $r): bool => ! in_array($r->status, [OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::Cancelled], true))
            ->schema([
                Select::make('vehicle_id')->label('Vehicle (optional)')->searchable()
                    ->options(fn (Order $record): array => app(ShipmentService::class)->selectableVehicles($record)
                        ->with('company')->orderBy('registration_number')->limit(200)->get()
                        ->mapWithKeys(fn ($v) => [$v->id => $fleetLabel($v->registration_number, $v)])->all()),
                Select::make('driver_id')->label('Driver (optional)')->searchable()
                    ->options(fn (Order $record): array => app(ShipmentService::class)->selectableDrivers($record)
                        ->with('company')->orderBy('name')->limit(200)->get()
                        ->mapWithKeys(fn ($d) => [$d->id => $fleetLabel($d->name, $d)])->all()),
                TextInput::make('origin')->maxLength(200),
                TextInput::make('destination')->maxLength(200),
            ])
            ->action(function (Order $record, array $data): void {
                try {
                    $shipment = app(ShipmentService::class)->createFromOrder($record, $data);
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }

                activity('order')->performedOn($record)->causedBy(auth()->user())
                    ->event('shipment_created')
                    ->withProperties(['waybill_number' => $shipment->waybill_number])
                    ->log('Supplier created a shipment waybill');

                Notification::make()->title("Waybill {$shipment->waybill_number} created")->success()
                    ->actions([
                        Action::make('open')->label('Open waybill')
                            ->url(app(ShipmentWaybillQrCodeService::class)->waybillUrl($shipment), shouldOpenInNewTab: true),
                    ])->send();
            });
    }

    /**
     * "Mark delivered" — same CommandBus routing as shipAction() above, for
     * OrderDelivered.
     */
    protected static function deliverAction(): Action
    {
        return Action::make('deliver')->label('Mark delivered')->icon('heroicon-o-inbox-arrow-down')->color('success')
            ->requiresConfirmation()
            ->visible(fn (Order $r): bool => static::allows($r, OrderStatus::Delivered))
            ->action(fn (Order $record) => static::run(
                fn () => app(CommandBus::class)->dispatch(new RecordOrderDeliveryCommand(
                    orderId: $record->getKey(),
                    actingUserId: auth()->id(),
                )),
                'Mark delivered — done',
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
