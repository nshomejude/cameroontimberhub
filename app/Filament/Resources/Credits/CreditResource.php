<?php

namespace App\Filament\Resources\Credits;

use App\Filament\Resources\Credits\Pages\ListCredits;
use App\Filament\Resources\Credits\Tables\CreditsTable;
use App\Models\Credit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * /admin → Credits (billing engine M8, plan §19).
 *
 * The credit ledger is append-only — there is no edit/create-record form
 * and no delete; "Grant credit" (see Pages\ListCredits) writes a new
 * positive row via App\Services\Billing\CreditLedger, and a correction is
 * a new negative/positive row rather than an edit of an old one. Gated by
 * `pricing.manage` (reused from M5).
 */
class CreditResource extends Resource
{
    protected static ?string $model = Credit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.credits');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.credit_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.credit_many');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
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
        return CreditsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCredits::route('/'),
        ];
    }
}
