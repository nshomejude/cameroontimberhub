<?php

namespace App\Filament\Resources\AiApiKeyChangeRequests\Tables;

use App\Actions\Ai\ApproveAiApiKeyChange;
use App\Models\AiApiKeyChangeRequest;
use App\Services\TwoFactorStepUp;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class AiApiKeyChangeRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')->badge(),
                TextColumn::make('requestedBy.name')->label('Requested by'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('approvedBy.name')->label('Decided by')->placeholder('—'),
                TextColumn::make('expires_at')->label('Expires')->dateTime('d M Y H:i'),
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
                    ->schema([
                        TextInput::make('invite_token')
                            ->label('Invite token')
                            ->helperText('The one-time token the requester relayed to you out-of-band.')
                            ->required(),
                    ])
                    ->visible(fn (AiApiKeyChangeRequest $record): bool => $record->isPending()
                        && (int) $record->requested_by !== (int) auth()->id())
                    ->action(function (AiApiKeyChangeRequest $record, array $data): void {
                        $user = auth()->user();
                        $stepUp = app(TwoFactorStepUp::class);
                        $httpRequest = request();

                        if (! $user->hasTwoFactorEnabled()) {
                            Notification::make()
                                ->title('Two-factor authentication required')
                                ->body('Set up two-factor authentication before approving an API key change.')
                                ->danger()
                                ->send();

                            return;
                        }

                        if (! $stepUp->isRecentlyVerified($httpRequest)) {
                            Notification::make()
                                ->title('Re-verification required')
                                ->body('Please re-confirm your two-factor code before approving this key change.')
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
                            app(ApproveAiApiKeyChange::class)->execute($record, $user, $data['invite_token'], $httpRequest);
                            Notification::make()->title('API key change approved')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title('Could not approve')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
                        }
                    }),

                Action::make('rejectRequest')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (AiApiKeyChangeRequest $record): bool => $record->isPending()
                        && (int) $record->requested_by !== (int) auth()->id())
                    ->action(function (AiApiKeyChangeRequest $record): void {
                        $record->update(['status' => 'rejected', 'approved_by' => auth()->id(), 'decided_at' => now()]);
                        Notification::make()->title('Key change request rejected')->success()->send();
                    }),
            ]);
    }
}
