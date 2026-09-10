<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Admin oversight of orders. The commercial figures are read-only — they are a
 * snapshot of the accepted quote and belong to the two trading parties.
 *
 * Staff can do exactly two things here, both of which are administrative
 * records of something that happened off-platform: record a settlement, and
 * withdraw a receipt that was issued in error.
 */
class OrderResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.orders');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.leads');
    }
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;



    protected static ?string $recordTitleAttribute = 'reference_code';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('rfqs.triage');
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

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
        ];
    }
}
