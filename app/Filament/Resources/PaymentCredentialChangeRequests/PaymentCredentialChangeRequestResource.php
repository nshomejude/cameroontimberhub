<?php

namespace App\Filament\Resources\PaymentCredentialChangeRequests;

use App\Filament\Resources\PaymentCredentialChangeRequests\Pages\ListPaymentCredentialChangeRequests;
use App\Filament\Resources\PaymentCredentialChangeRequests\Tables\PaymentCredentialChangeRequestsTable;
use App\Models\PaymentCredentialChangeRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Two-person + step-up-2FA gated control over payment gateway credentials
 * (billing engine M12 / §8 — mirrors AiApiKeyChangeRequestResource exactly).
 * One admin proposes new credentials (header action, via
 * App\Actions\Payments\RequestPaymentCredentialChange); a DIFFERENT admin
 * approves them here by supplying the one-time invite token AND a recent 2FA
 * confirmation (App\Actions\Payments\ApprovePaymentCredentialChange). No
 * create/edit/delete — requests are decided only via the recordActions.
 */
class PaymentCredentialChangeRequestResource extends Resource
{
    protected static ?string $model = PaymentCredentialChangeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.nav.payment_credential_changes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function getModelLabel(): string
    {
        return __('messages.filament.model.payment_credential_change_one');
    }

    public static function getPluralModelLabel(): string
    {
        return __('messages.filament.model.payment_credential_change_many');
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
        return PaymentCredentialChangeRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentCredentialChangeRequests::route('/'),
        ];
    }
}
