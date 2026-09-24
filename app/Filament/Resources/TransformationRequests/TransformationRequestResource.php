<?php

namespace App\Filament\Resources\TransformationRequests;

use App\Filament\Resources\TransformationRequests\Pages\ListTransformationRequests;
use App\Filament\Resources\TransformationRequests\Tables\TransformationRequestsTable;
use App\Models\TransformationRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only staff visibility into the Transformation Request pipeline
 * (App\Services\TransformationRequestService) — the admin-panel counterpart
 * of App\Filament\Resources\LotTransformations, mirroring
 * WebhookDeliveryResource's read-only shape (no create/edit/delete: every
 * mutation happens through the API status machine, not the admin panel).
 * Gated on `products.manage`, the same permission LotTransformationResource
 * uses, since both surfaces sit in the same "supply-chain operations" area.
 */
class TransformationRequestResource extends Resource
{
    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.transformation_requests');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.catalogue');
    }

    protected static ?string $model = TransformationRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return static::hasPermission();
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

    protected static function hasPermission(): bool
    {
        return auth()->user()?->can('products.manage') ?? false;
    }

    public static function table(Table $table): Table
    {
        return TransformationRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransformationRequests::route('/'),
        ];
    }
}
