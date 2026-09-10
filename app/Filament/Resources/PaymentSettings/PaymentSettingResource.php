<?php

namespace App\Filament\Resources\PaymentSettings;

use App\Filament\Resources\PaymentSettings\Pages\ListPaymentSettings;
use App\Filament\Resources\PaymentSettings\Tables\PaymentSettingsTable;
use App\Models\PaymentSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only view of each payment provider's credential state (billing engine
 * M12 / §8). The actual credential values are NEVER rendered — only
 * "set / not set", the environment, the is_live toggle, and who last changed
 * them. Credentials are changed through the two-person + fresh-2FA
 * PaymentCredentialChangeRequest flow, not here.
 */
class PaymentSettingResource extends Resource
{
    protected static ?string $model = PaymentSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.payment_settings');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.payment_setting_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.payment_setting_many');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('payments.manage');
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
        return PaymentSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentSettings::route('/'),
        ];
    }
}
