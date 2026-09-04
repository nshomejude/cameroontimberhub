<?php

namespace App\Filament\Resources\VerificationRevocationRequests\Tables;

use App\Actions\Verification\ApproveVerificationRevocation;
use App\Models\VerificationRevocationRequest;
use App\Services\TwoFactorStepUp;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class VerificationRevocationRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.legal_name')->label('Company')->searchable()->sortable(),
                TextColumn::make('requestedBy.name')->label('Requested by'),
                TextColumn::make('reason')->limit(60),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('approvedBy.name')->label('Decided by')->placeholder('—'),
                TextColumn::make('created_at')->label('Requested')->dateTime('d M Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected']),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('This revokes the company\'s active verification badges. You must be a different staff member than the one who requested this.')
                    ->visible(fn (VerificationRevocationRequest $record): bool => $record->isPending()
                        && (int) $record->requested_by !== (int) auth()->id())
                    ->action(function (VerificationRevocationRequest $record): void {
                        $user = auth()->user();
                        $stepUp = app(TwoFactorStepUp::class);
                        $request = request();

                        // Blueprint §39 step-up re-auth: revoking a company's
                        // verification badges is high-risk, so it requires a
                        // TOTP confirmation within the last few minutes. This
                        // is a Filament Livewire action (no route in the
                        // middleware stack to attach RequiresRecentTwoFactor
                        // to), so the same TwoFactorStepUp window is checked
                        // directly here instead.
                        if (! $user->hasTwoFactorEnabled()) {
                            Notification::make()
                                ->title('Two-factor authentication required')
                                ->body('Set up two-factor authentication before approving a verification revocation.')
                                ->danger()
                                ->send();

                            return;
                        }

                        if (! $stepUp->isRecentlyVerified($request)) {
                            Notification::make()
                                ->title('Re-verification required')
                                ->body('Please re-confirm your two-factor code before approving this revocation.')
                                ->danger()
                                ->actions([
                                    \Filament\Notifications\Actions\Action::make('verify')
                                        ->label('Re-verify now')
                                        ->url(route('two-factor.challenge.show'))
                                        ->openUrlInNewTab(false),
                                ])
                                ->send();

                            return;
                        }

                        try {
                            app(ApproveVerificationRevocation::class)->execute($record, $user);
                            Notification::make()->title('Revocation approved')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title('Could not approve')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
                        }
                    }),

                Action::make('rejectRequest')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (VerificationRevocationRequest $record): bool => $record->isPending()
                        && (int) $record->requested_by !== (int) auth()->id())
                    ->action(function (VerificationRevocationRequest $record): void {
                        $record->update(['status' => 'rejected', 'approved_by' => auth()->id()]);
                        Notification::make()->title('Revocation request rejected')->success()->send();
                    }),
            ]);
    }
}
