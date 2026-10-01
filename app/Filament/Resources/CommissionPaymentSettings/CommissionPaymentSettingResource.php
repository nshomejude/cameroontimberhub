<?php

namespace App\Filament\Resources\CommissionPaymentSettings;

use App\Filament\Resources\CommissionPaymentSettings\Pages\ListCommissionPaymentSettings;
use App\Models\CommissionPaymentSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * /admin → Commission payment instructions: the single
 * CommissionPaymentSetting row — the platform's MTN MoMo / Orange Money
 * numbers and bank account that suppliers pay their commission statements
 * into (owner decision 2026-10-01). Printed on every statement and returned
 * by the supplier API. Same single-row-table pattern as
 * ReferralSettingResource; finance only (`payments.manage`). Every change is
 * written to the activity log.
 */
class CommissionPaymentSettingResource extends Resource
{
    protected static ?string $model = CommissionPaymentSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Commission payment instructions';

    protected static ?string $modelLabel = 'commission payment instructions';

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
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
        return (bool) auth()->user()?->can('payments.manage');
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('MTN Mobile Money')->columns(2)->schema([
                TextInput::make('mtn_momo_number')->label('MoMo number')->tel()->maxLength(40),
                TextInput::make('mtn_momo_name')->label('Account name')->maxLength(150)->requiredWith('mtn_momo_number'),
            ]),
            Section::make('Orange Money')->columns(2)->schema([
                TextInput::make('orange_money_number')->label('Orange Money number')->tel()->maxLength(40),
                TextInput::make('orange_money_name')->label('Account name')->maxLength(150)->requiredWith('orange_money_number'),
            ]),
            Section::make('Bank deposit / transfer')->columns(2)->schema([
                TextInput::make('bank_name')->label('Bank')->maxLength(150),
                TextInput::make('bank_account_name')->label('Account name')->maxLength(150)->requiredWith('bank_name'),
                TextInput::make('bank_account_number')->label('Account number')->maxLength(60),
                TextInput::make('bank_iban')->label('IBAN')->maxLength(60),
                TextInput::make('bank_swift')->label('SWIFT / BIC')->maxLength(20),
                TextInput::make('bank_branch')->label('Branch')->maxLength(150),
            ]),
            Textarea::make('extra_instructions')->label('Extra instructions shown to suppliers')->maxLength(2000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('mtn_momo_number')->label('MTN MoMo')->placeholder('not set'),
                TextColumn::make('orange_money_number')->label('Orange Money')->placeholder('not set'),
                TextColumn::make('bank_name')->label('Bank')->placeholder('not set'),
                TextColumn::make('bank_account_number')->label('Account')->placeholder('—'),
                TextColumn::make('updatedBy.name')->label('Changed by')->placeholder('—'),
                TextColumn::make('updated_at')->label('Last changed')->dateTime('d M Y H:i'),
            ])
            ->paginated(false)
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['updated_by'] = auth()->id();

                        return $data;
                    })
                    ->after(function (CommissionPaymentSetting $record): void {
                        activity('commission_collection')
                            ->performedOn($record)
                            ->causedBy(auth()->user())
                            ->event('payment_instructions_updated')
                            ->withProperties($record->only([
                                'mtn_momo_number', 'mtn_momo_name', 'orange_money_number', 'orange_money_name',
                                'bank_name', 'bank_account_name', 'bank_account_number', 'bank_iban', 'bank_swift', 'bank_branch',
                            ]))
                            ->log('Commission payment instructions updated');
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommissionPaymentSettings::route('/'),
        ];
    }
}
