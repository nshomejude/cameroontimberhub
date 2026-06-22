<?php

namespace App\Filament\Resources\VerificationRequests\Tables;

use App\Enums\VerificationRequestStatus;
use App\Models\VerificationRequest;
use App\Services\VerificationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VerificationRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('company.legal_name')->label('Company')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (VerificationRequestStatus $state): string => $state->label())
                    ->color(fn (VerificationRequestStatus $state): string => $state->color()),
                TextColumn::make('requested_badges')
                    ->label('Requested')
                    ->getStateUsing(fn (VerificationRequest $record): string => implode(', ', (array) $record->requested_badges)),
                TextColumn::make('assignedTo.name')->label('Reviewer')->placeholder('—'),
                TextColumn::make('created_at')->label('Submitted')->date('d M Y')->sortable(),
                TextColumn::make('decided_at')->date('d M Y')->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(collect(VerificationRequestStatus::cases())->mapWithKeys(fn (VerificationRequestStatus $s) => [$s->value => $s->label()])->all()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('startReview')->label('Start review')
                    ->icon('heroicon-o-play-circle')->color('info')->requiresConfirmation()
                    ->visible(fn (VerificationRequest $record): bool => $record->status === VerificationRequestStatus::Pending && static::canReview())
                    ->action(function (VerificationRequest $record): void {
                        app(VerificationService::class)->startReview($record, auth()->user());
                        Notification::make()->title('Review started')->success()->send();
                    }),

                Action::make('approve')->label('Approve')
                    ->icon('heroicon-o-check-badge')->color('success')->requiresConfirmation()
                    ->modalDescription('Issues the satisfied requested badges and marks the company verified.')
                    ->visible(fn (VerificationRequest $record): bool => in_array($record->status, [VerificationRequestStatus::Pending, VerificationRequestStatus::InReview], true) && static::canReview())
                    ->action(function (VerificationRequest $record): void {
                        $result = app(VerificationService::class)->approve($record, auth()->user());
                        $body = 'Issued: '.(implode(', ', $result['issued']) ?: 'none');
                        if ($result['skipped']) {
                            $body .= ' · Skipped: '.implode(', ', $result['skipped']);
                        }
                        Notification::make()->title('Verification approved')->body($body)->success()->send();
                    }),

                Action::make('reject')->label('Reject')
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (VerificationRequest $record): bool => in_array($record->status, [VerificationRequestStatus::Pending, VerificationRequestStatus::InReview], true) && static::canReview())
                    ->schema([Textarea::make('notes')->label('Decision notes')->required()->maxLength(500)])
                    ->action(function (VerificationRequest $record, array $data): void {
                        app(VerificationService::class)->reject($record, $data['notes'], auth()->user());
                        Notification::make()->title('Verification rejected')->success()->send();
                    }),
            ]);
    }

    protected static function canReview(): bool
    {
        return (bool) auth()->user()?->can('verification.review');
    }
}
