<?php

namespace App\Filament\Resources\ApiKeyUsages;

use App\Filament\Resources\ApiKeyUsages\Pages\ListApiKeyUsages;
use App\Filament\Resources\ApiKeyUsages\Tables\ApiKeyUsagesTable;
use App\Models\ApiKeyMeta;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only admin view of API usage across every issued key (architecture
 * plan §4 — "API as a product": table-stakes for pricing/rate-limiting).
 * Mirrors App\Filament\Resources\ApiKeyIssuanceRequests's gating style —
 * same `api-keys.manage` permission (no new permission added), no
 * create/edit/delete since this is a derived view over usage data, not
 * something an admin authors directly.
 */
class ApiKeyUsageResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.api_key_usage');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.api_key_usage_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.api_key_usage_many');
    }
    protected static ?string $model = ApiKeyMeta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;





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
        return ApiKeyUsagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiKeyUsages::route('/'),
        ];
    }
}
