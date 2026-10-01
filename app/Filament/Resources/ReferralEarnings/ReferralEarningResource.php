<?php

namespace App\Filament\Resources\ReferralEarnings;

use App\Enums\ReferralEarningStatus;
use App\Enums\ReferralPayoutStatus;
use App\Filament\Resources\ReferralEarnings\Pages\ListReferralEarnings;
use App\Models\ReferralEarning;
use App\Models\ReferralPayoutProfile;
use App\Models\User;
use App\Services\Referrals\ReferralPayoutService;
use App\Services\TwoFactorStepUp;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * /admin → Referral earnings. Referral commissions with the pending →
 * approved → paid lifecycle. Viewing needs `billing.view`; approving a
 * commission needs `pricing.manage`; anything that pays money needs
 * `payments.manage` (ReferralPayoutService):
 *  - "Request PayPal payout" (single + bulk) — step 1 of the two-person control
 *  - "Approve payout" (single + bulk) — a DIFFERENT admin; calls PayPal
 *  - "Refresh payout status" — re-check PayPal (safe retry if the outcome is unknown)
 *  - "Mark paid manually" — MoMo / bank, with a mandatory reference note
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

    public static function canManagePayouts(): bool
    {
        return (bool) auth()->user()?->can('payments.manage');
    }

    private static function payouts(): ReferralPayoutService
    {
        return app(ReferralPayoutService::class);
    }

    private static function fail(string $title, ValidationException $e): void
    {
        Notification::make()->title($title)->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
    }

    private static function awaitingSecondApproval(ReferralEarning $record): bool
    {
        return $record->latestPayout?->status === ReferralPayoutStatus::Requested;
    }

    /**
     * The approver-side 2FA step-up, mirrored from the payment-credential
     * approval table action (Livewire actions bypass route middleware).
     * Returns false (after telling the admin) when approval must not proceed.
     */
    private static function stepUpSatisfied(): bool
    {
        if (! config('auth.require_staff_2fa')) {
            return true;
        }

        /** @var User $user */
        $user = auth()->user();

        if (! $user->hasTwoFactorEnabled()) {
            Notification::make()->title('Two-factor authentication required')
                ->body('Set up two-factor authentication before approving a payout.')->danger()->send();

            return false;
        }

        if (! app(TwoFactorStepUp::class)->isRecentlyVerified(request())) {
            Notification::make()->title('Re-verification required')
                ->body('Please re-confirm your two-factor code before approving this payout.')
                ->danger()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('verify')
                        ->label('Re-verify now')
                        ->url(route('two-factor.challenge.show'))
                        ->openUrlInNewTab(false),
                ])
                ->send();

            return false;
        }

        return true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['referrer', 'referredCompany', 'latestPayout']))
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('referrer.name')->label('Referrer')->searchable(),
                TextColumn::make('referredCompany.legal_name')->label('Referred company')->searchable(),
                TextColumn::make('source_reference')->label('Source')->searchable(),
                TextColumn::make('base_amount')->label('Basis (excl. tax & fees)')
                    ->formatStateUsing(fn ($state, ReferralEarning $record) => ReferralEarning::money((float) $state, $record->currency)),
                TextColumn::make('rate_percent')->label('Rate')->suffix('%'),
                TextColumn::make('amount')->label('Commission')
                    ->formatStateUsing(fn ($state, ReferralEarning $record) => $record->amountLabel()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (ReferralEarningStatus $state) => $state->label())
                    ->color(fn (ReferralEarningStatus $state) => $state->color()),
                TextColumn::make('latestPayout.status')->label('Payout')->badge()
                    ->formatStateUsing(fn (ReferralPayoutStatus $state, ReferralEarning $record) => $state->label()
                        .($record->latestPayout?->method === 'manual' ? ' (manual)' : ''))
                    ->color(fn (ReferralPayoutStatus $state) => $state->color())
                    ->tooltip(fn (ReferralEarning $record) => $record->latestPayout?->failure_reason ?? $record->latestPayout?->reference_note)
                    ->placeholder('—'),
                TextColumn::make('payout_email')->label('PayPal email')
                    ->state(fn (ReferralEarning $record) => ReferralPayoutProfile::mask(ReferralPayoutProfile::paypalEmailFor($record->referrer)))
                    ->placeholder('not set'),
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

                Action::make('requestPayout')
                    ->label('Request PayPal payout')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Step 1 of 2. A different administrator must approve before any money is sent.')
                    ->visible(fn (ReferralEarning $record) => self::canManagePayouts()
                        && $record->status === ReferralEarningStatus::Approved
                        && ! self::payouts()->hasLivePayout($record))
                    ->disabled(fn (ReferralEarning $record) => self::payouts()->paypalBlocker($record) !== null)
                    ->tooltip(fn (ReferralEarning $record) => self::payouts()->paypalBlocker($record))
                    ->action(function (ReferralEarning $record): void {
                        try {
                            self::payouts()->requestPayPalPayout($record, auth()->user());
                            Notification::make()->title('Payout requested')->body('Ask a different administrator to approve it.')->success()->send();
                        } catch (ValidationException $e) {
                            self::fail('Could not request payout', $e);
                        }
                    }),

                Action::make('approvePayout')
                    ->label('Approve payout')
                    ->icon('heroicon-o-shield-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve PayPal payout')
                    ->modalDescription(fn (ReferralEarning $record) => 'Send '.$record->amountLabel().' via PayPal to '
                        .($record->latestPayout?->receiver_email ?? '?').' (requested by '
                        .($record->latestPayout?->requestedBy?->name ?? 'unknown').'). This moves real money.')
                    ->visible(fn (ReferralEarning $record) => self::canManagePayouts()
                        && self::awaitingSecondApproval($record)
                        && (int) $record->latestPayout->requested_by !== (int) auth()->id())
                    ->action(function (ReferralEarning $record): void {
                        if (! self::stepUpSatisfied()) {
                            return;
                        }

                        try {
                            $payout = self::payouts()->approvePayPalPayout($record->latestPayout, auth()->user(), request());
                            Notification::make()->title('Payout '.strtolower($payout->status->label()))
                                ->body($payout->failure_reason)->{$payout->status === ReferralPayoutStatus::Failed ? 'danger' : 'success'}()->send();
                        } catch (ValidationException $e) {
                            self::fail('Could not approve payout', $e);
                        }
                    }),

                Action::make('rejectPayout')
                    ->label('Reject payout request')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->schema([Textarea::make('reason')->label('Reason')->maxLength(500)])
                    ->visible(fn (ReferralEarning $record) => self::canManagePayouts() && self::awaitingSecondApproval($record))
                    ->action(function (ReferralEarning $record, array $data): void {
                        try {
                            self::payouts()->rejectPayPalPayout($record->latestPayout, auth()->user(), $data['reason'] ?? null);
                            Notification::make()->title('Payout request rejected')->success()->send();
                        } catch (ValidationException $e) {
                            self::fail('Could not reject', $e);
                        }
                    }),

                Action::make('refreshPayout')
                    ->label('Refresh payout status')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (ReferralEarning $record) => self::canManagePayouts()
                        && $record->latestPayout?->isPaypal()
                        && in_array($record->latestPayout->status, [ReferralPayoutStatus::Processing, ReferralPayoutStatus::Unclaimed], true))
                    ->action(function (ReferralEarning $record): void {
                        try {
                            $payout = self::payouts()->refresh($record->latestPayout, auth()->user());
                            Notification::make()->title('Payout: '.$payout->status->label())->body($payout->failure_reason)->info()->send();
                        } catch (ValidationException $e) {
                            self::fail('Could not refresh', $e);
                        }
                    }),

                Action::make('markPaidManually')
                    ->label('Mark paid manually')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->modalDescription('Record a commission you paid outside PayPal (MoMo / bank transfer). The reference is kept in the audit log.')
                    ->schema([
                        Textarea::make('reference_note')
                            ->label('Payment reference (MoMo transaction id, bank reference, …)')
                            ->required()->minLength(5)->maxLength(1000),
                    ])
                    ->visible(fn (ReferralEarning $record) => self::canManagePayouts()
                        && in_array($record->status, [ReferralEarningStatus::Pending, ReferralEarningStatus::Approved], true)
                        && ! in_array($record->latestPayout?->status, [ReferralPayoutStatus::Requested, ReferralPayoutStatus::Unclaimed], true))
                    ->action(function (ReferralEarning $record, array $data): void {
                        try {
                            self::payouts()->markPaidManually($record, auth()->user(), (string) $data['reference_note']);
                            Notification::make()->title('Commission marked paid')->success()->send();
                        } catch (ValidationException $e) {
                            self::fail('Could not mark paid', $e);
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('requestPayouts')
                        ->label('Request PayPal payouts')
                        ->icon('heroicon-o-paper-airplane')
                        ->requiresConfirmation()
                        ->modalDescription('Step 1 of 2 for each approved, unpaid commission whose referrer has a PayPal email. A different administrator must approve.')
                        ->visible(fn () => self::canManagePayouts())
                        ->action(function (Collection $records): void {
                            if (! self::payouts()->paypalConfigured()) {
                                Notification::make()->title('PayPal is not configured')
                                    ->body('Configure PayPal (with Payouts enabled) in Payment settings, or mark commissions paid manually.')->danger()->send();

                                return;
                            }

                            $result = self::payouts()->requestMany($records, auth()->user());
                            Notification::make()->title($result['ok'].' payout(s) requested')
                                ->body($result['errors'] === [] ? 'Ask a different administrator to approve them.' : 'Skipped: '.implode(' ', $result['errors']))
                                ->{$result['errors'] === [] ? 'success' : 'warning'}()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('approvePayouts')
                        ->label('Approve payouts')
                        ->icon('heroicon-o-shield-check')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription('Sends real money via PayPal for every selected payout request you did not raise yourself.')
                        ->visible(fn () => self::canManagePayouts())
                        ->action(function (Collection $records): void {
                            if (! self::stepUpSatisfied()) {
                                return;
                            }

                            $ok = 0;
                            $errors = [];
                            foreach ($records as $record) {
                                $payout = $record->latestPayout;
                                if ($payout?->status !== ReferralPayoutStatus::Requested) {
                                    continue;
                                }

                                try {
                                    $payout = self::payouts()->approvePayPalPayout($payout, auth()->user(), request());
                                    $payout->status === ReferralPayoutStatus::Failed
                                        ? $errors[] = '#'.$record->getKey().': '.$payout->failure_reason
                                        : $ok++;
                                } catch (ValidationException $e) {
                                    $errors[] = '#'.$record->getKey().': '.collect($e->errors())->flatten()->first();
                                }
                            }

                            Notification::make()->title($ok.' payout(s) sent to PayPal')
                                ->body($errors === [] ? null : implode(' ', $errors))
                                ->{$errors === [] ? 'success' : 'warning'}()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReferralEarnings::route('/'),
        ];
    }
}
