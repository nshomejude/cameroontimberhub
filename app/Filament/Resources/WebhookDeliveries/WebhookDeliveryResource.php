<?php

namespace App\Filament\Resources\WebhookDeliveries;

use App\Filament\Resources\WebhookDeliveries\Pages\ListWebhookDeliveries;
use App\Filament\Resources\WebhookDeliveries\Tables\WebhookDeliveriesTable;
use App\Models\WebhookDelivery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only admin delivery log for webhooks (architecture plan, Task 0.5).
 * Gated on the existing `api-keys.manage` permission — webhook/API
 * management is one product surface per the plan doc, so this deliberately
 * reuses that permission rather than adding a new one. Mirrors
 * App\Filament\Resources\ApiKeyIssuanceRequests's permission-gating style
 * (no create/edit/delete — the only mutation is the "Retry now" row action
 * in WebhookDeliveriesTable).
 */
class WebhookDeliveryResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.webhook_deliveries');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.webhook_delivery_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.webhook_delivery_many');
    }
    protected static ?string $model = WebhookDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBoltSlash;





    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('api-keys.manage');
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
        return WebhookDeliveriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookDeliveries::route('/'),
        ];
    }
}
