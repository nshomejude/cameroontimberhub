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
    protected static ?string $model = ApiKeyMeta::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'API key usage';

    protected static ?string $modelLabel = 'API key usage';

    protected static ?string $pluralModelLabel = 'API key usage';

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
