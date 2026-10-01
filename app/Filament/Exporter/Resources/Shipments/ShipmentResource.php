<?php

namespace App\Filament\Exporter\Resources\Shipments;

use App\Filament\Exporter\Resources\Shipments\Pages\ListShipments;
use App\Filament\Exporter\Resources\Shipments\Pages\ViewShipment;
use App\Models\CheckpointUpdate;
use App\Models\Shipment;
use App\Services\ShipmentService;
use App\Services\ShipmentWaybillQrCodeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Read-only shipment list + detail (with checkpoint history) for the
 * operator side of an order: the carrier company moving it and the
 * supplier who sold it. Scoping is ShipmentService::visibleTo() — the same
 * boundary the supplier shipments API uses. Shipments are created from the
 * Orders table ("Create shipment / waybill") or automatically on ship;
 * checkpoints are recorded through the offline-capable capture page.
 */
class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?string $recordTitleAttribute = 'waybill_number';

    protected static ?int $navigationSort = 45;

    public static function getNavigationLabel(): string
    {
        return __('Shipments');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if (! $user) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return app(ShipmentService::class)->visibleTo($user)
            ->with(['order', 'carrierCompany', 'vehicle', 'driver']);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('waybill_number')->label('Waybill')->searchable()->copyable(),
                TextColumn::make('order.reference_code')->label('Order')->searchable(),
                TextColumn::make('carrierCompany.name')->label('Carrier')->placeholder('—'),
                TextColumn::make('vehicle.registration_number')->label('Vehicle')->placeholder('—'),
                TextColumn::make('driver.name')->label('Driver')->placeholder('—'),
                TextColumn::make('current_status')->label('Status')->badge()
                    ->state(fn (Shipment $r): string => $r->latestCheckpoint()?->status->label() ?? 'Awaiting dispatch'),
                TextColumn::make('created_at')->label('Created')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                static::waybillAction(),
                static::assignAction(),
                Action::make('checkpoint')->label('Record checkpoint')->icon('heroicon-o-map-pin')
                    ->url(fn (Shipment $r): string => route('logistics.checkpoints.create', $r))
                    ->openUrlInNewTab(),
            ]);
    }

    /**
     * "Assign carrier / vehicle" — supplier side only (a member of the
     * order's supplier company; the carrier sees the shipment but cannot
     * reassign it). Same selection rules as creation, via
     * ShipmentService::updateAssignment(); a newly assigned third-party
     * carrier is notified there.
     */
    public static function assignAction(): Action
    {
        $fleetLabel = fn (string $name, $model): string => "{$name} ({$model->company?->name})";
        $service = fn (): ShipmentService => app(ShipmentService::class);

        return Action::make('assign')->label('Assign carrier / vehicle')->icon('heroicon-o-truck')
            ->visible(fn (Shipment $r): bool => (bool) auth()->user()?->companies()->whereKey($r->order?->company_id)->exists())
            ->fillForm(fn (Shipment $r): array => $r->only(['carrier_company_id', 'vehicle_id', 'driver_id', 'origin', 'destination']))
            ->schema([
                Select::make('carrier_company_id')->label('Carrier company (optional)')->searchable()
                    ->helperText('Your own company or any logistics company. Taken from the vehicle/driver when one is chosen.')
                    ->options(fn (Shipment $record): array => $service()->selectableCarriers($record->order)
                        ->orderBy('legal_name')->limit(200)->get()
                        ->mapWithKeys(fn ($c) => [$c->id => $c->name])->all()),
                Select::make('vehicle_id')->label('Vehicle (optional)')->searchable()
                    ->options(fn (Shipment $record): array => $service()->selectableVehicles($record->order)
                        ->with('company')->orderBy('registration_number')->limit(200)->get()
                        ->mapWithKeys(fn ($v) => [$v->id => $fleetLabel($v->registration_number, $v)])->all()),
                Select::make('driver_id')->label('Driver (optional)')->searchable()
                    ->options(fn (Shipment $record): array => $service()->selectableDrivers($record->order)
                        ->with('company')->orderBy('name')->limit(200)->get()
                        ->mapWithKeys(fn ($d) => [$d->id => $fleetLabel($d->name, $d)])->all()),
                TextInput::make('origin')->maxLength(200),
                TextInput::make('destination')->maxLength(200),
            ])
            ->action(function (Shipment $record, array $data) use ($service): void {
                try {
                    $service()->updateAssignment($record, $data);
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()->title('Shipment assignment updated')->success()->send();
            });
    }

    public static function waybillAction(): Action
    {
        return Action::make('waybill')->label('Waybill')->icon('heroicon-o-qr-code')
            ->url(fn (Shipment $r): string => app(ShipmentWaybillQrCodeService::class)->waybillUrl($r))
            ->openUrlInNewTab();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Shipment')->columns(3)->schema([
                TextEntry::make('waybill_number')->label('Waybill')->copyable(),
                TextEntry::make('order.reference_code')->label('Order'),
                TextEntry::make('carrierCompany.name')->label('Carrier')->placeholder('—'),
                TextEntry::make('vehicle.registration_number')->label('Vehicle')->placeholder('—'),
                TextEntry::make('driver.name')->label('Driver')->placeholder('—'),
                TextEntry::make('origin')->placeholder('—'),
                TextEntry::make('destination')->placeholder('—'),
                TextEntry::make('waybill_url')->label('Public waybill')
                    ->state(fn (Shipment $r): string => app(ShipmentWaybillQrCodeService::class)->waybillUrl($r))
                    ->url(fn (?string $state): ?string => $state)->openUrlInNewTab(),
            ]),
            Section::make('Checkpoint history')->schema([
                RepeatableEntry::make('checkpoint_history')->hiddenLabel()
                    ->state(fn (Shipment $r) => $r->checkpointUpdates()->orderBy('occurred_at')->orderBy('id')->get()
                        ->map(fn (CheckpointUpdate $c): array => [
                            'status' => $c->status->label(),
                            'location' => $c->location,
                            'notes' => $c->notes,
                            'occurred_at' => $c->occurred_at?->format('d M Y H:i'),
                        ])->all())
                    ->columns(4)
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('location')->placeholder('—'),
                        TextEntry::make('notes')->placeholder('—'),
                        TextEntry::make('occurred_at')->label('When'),
                    ])
                    ->placeholder('No checkpoints recorded yet.'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListShipments::route('/'),
            'view' => ViewShipment::route('/{record}'),
        ];
    }
}
