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
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                Action::make('checkpoint')->label('Record checkpoint')->icon('heroicon-o-map-pin')
                    ->url(fn (Shipment $r): string => route('logistics.checkpoints.create', $r))
                    ->openUrlInNewTab(),
            ]);
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
