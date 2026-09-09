<?php

namespace App\Filament\Resources\ApiKeyIssuanceRequests;

use App\Filament\Resources\ApiKeyIssuanceRequests\Pages\ListApiKeyIssuanceRequests;
use App\Filament\Resources\ApiKeyIssuanceRequests\Tables\ApiKeyIssuanceRequestsTable;
use App\Models\ApiKeyIssuanceRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Two-person + step-up-2FA gated control over `/api/v1` product API keys
 * (mirrors App\Filament\Resources\AiApiKeyChangeRequests exactly). One
 * admin proposes a named key with a set of Sanctum abilities for a company
 * (header action below, via App\Actions\ApiKeys\RequestApiKeyIssuance); a
 * DIFFERENT admin approves it here by supplying the one-time invite token
 * AND a recent 2FA confirmation (App\Actions\ApiKeys\ApproveApiKeyIssuance),
 * which is when the real Sanctum token is created. No create/edit/delete —
 * requests are decided only via the recordActions in the table.
 */
class ApiKeyIssuanceRequestResource extends Resource
{
    protected static ?string $model = ApiKeyIssuanceRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'API key requests';

    protected static ?string $modelLabel = 'API key issuance request';

    protected static ?string $pluralModelLabel = 'API key issuance requests';

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
        return ApiKeyIssuanceRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiKeyIssuanceRequests::route('/'),
        ];
    }
}
