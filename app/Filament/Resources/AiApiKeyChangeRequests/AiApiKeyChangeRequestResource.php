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

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.ai_api_key_changes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.ai_api_key_change_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.ai_api_key_change_many');
    }
    protected static ?string $model = AiApiKeyChangeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;



    // Filament derives the model/plural labels and breadcrumb title from
    // the model class name by default (Str::title(Str::snake(...))), which
    // doesn't know "Ai"/"Api" are acronyms — it renders "Ai Api Key Change
    // Requests" instead of "AI API Key Change Requests". Set explicitly.


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
