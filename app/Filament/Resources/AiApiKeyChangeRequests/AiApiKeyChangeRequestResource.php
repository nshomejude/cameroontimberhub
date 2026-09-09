<?php

namespace App\Filament\Resources\AiApiKeyChangeRequests;

use App\Filament\Resources\AiApiKeyChangeRequests\Pages\ListAiApiKeyChangeRequests;
use App\Filament\Resources\AiApiKeyChangeRequests\Tables\AiApiKeyChangeRequestsTable;
use App\Models\AiApiKeyChangeRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Two-person + step-up-2FA gated control over AI provider API keys
 * (blueprint §39/§88-89 pattern, mirroring VerificationRevocationRequest).
 * One admin proposes a new key (header action below, via
 * App\Actions\Ai\RequestAiApiKeyChange); a DIFFERENT admin approves it here
 * by supplying the one-time invite token AND a recent 2FA confirmation
 * (App\Actions\Ai\ApproveAiApiKeyChange). No create/edit/delete — requests
 * are decided only via the recordActions in the table.
 */
class AiApiKeyChangeRequestResource extends Resource
{
    protected static ?string $model = AiApiKeyChangeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?string $navigationLabel = 'AI API key changes';

    // Filament derives the model/plural labels and breadcrumb title from
    // the model class name by default (Str::title(Str::snake(...))), which
    // doesn't know "Ai"/"Api" are acronyms — it renders "Ai Api Key Change
    // Requests" instead of "AI API Key Change Requests". Set explicitly.
    protected static ?string $modelLabel = 'AI API key change request';

    protected static ?string $pluralModelLabel = 'AI API key change requests';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('ai.manage');
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
        return AiApiKeyChangeRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiApiKeyChangeRequests::route('/'),
        ];
    }
}
