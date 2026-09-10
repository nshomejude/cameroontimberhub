<?php

namespace App\Filament\Resources\Disputes;

use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Disputes\Tables\DisputesTable;
use App\Models\Dispute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Admin surface for the formal Dispute Resolution workflow (blueprint §64).
 * Disputes are opened by company/buyer users through the public workflow,
 * not authored by staff, so there is no create form here — only review and
 * the decision action, gated behind `disputes.manage`.
 */
class DisputeResource extends Resource
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.disputes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.trust_safety');
    }
    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;


    protected static ?string $recordTitleAttribute = 'id';

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('disputes.manage');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->can('disputes.manage');
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return DisputesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisputes::route('/'),
        ];
    }
}
