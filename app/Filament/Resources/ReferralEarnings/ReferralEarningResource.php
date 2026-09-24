<?php

namespace App\Filament\Resources\ReferralEarnings;

use App\Enums\ReferralEarningStatus;
use App\Filament\Resources\ReferralEarnings\Pages\ListReferralEarnings;
use App\Models\ReferralEarning;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * /admin → Referral earnings. Read-only list of referral commissions with
 * the pending → approved → paid actions. Viewing needs `billing.view`;
 * approving / marking paid needs `pricing.manage` (finance authority).
 */
class ReferralEarningResource extends Resource
{
    protected static ?string $model = ReferralEarning::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Referral earnings';

    protected static ?string $modelLabel = 'referral earning';

    public static function getNavigationGroup(): ?string
    {
        return __('messages.filament.groups.platform');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('billing.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canManage(): bool
    {
        return (bool) auth()->user()?->can('pricing.manage');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('referrer.name')->label('Referrer')->searchable(),
                TextColumn::make('referredCompany.legal_name')->label('Referred company')->searchable(),
                TextColumn::make('source_reference')->label('Source')->searchable(),
                TextColumn::make('base_amount')->label('Payment')
                    ->formatStateUsing(fn ($state, ReferralEarning $record) => ReferralEarning::money((float) $state, $record->currency)),
                TextColumn::make('rate_percent')->label('Rate')->suffix('%'),
                TextColumn::make('amount')->label('Commission')
                    ->formatStateUsing(fn ($state, ReferralEarning $record) => $record->amountLabel()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ReferralEarningStatus $state) => $state->label())
                    ->color(fn (ReferralEarningStatus $state) => $state->color()),
                TextColumn::make('created_at')->label('Earned')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('paid_at')->label('Paid')->dateTime('d M Y')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(ReferralEarningStatus::cases())
                    ->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (ReferralEarning $record) => self::canManage() && $record->status === ReferralEarningStatus::Pending)
                    ->action(fn (ReferralEarning $record) => $record->markApproved(auth()->user())),
                Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (ReferralEarning $record) => self::canManage()
                        && in_array($record->status, [ReferralEarningStatus::Pending, ReferralEarningStatus::Approved], true))
                    ->action(fn (ReferralEarning $record) => $record->markPaid(auth()->user())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferralEarnings::route('/'),
        ];
    }
}
