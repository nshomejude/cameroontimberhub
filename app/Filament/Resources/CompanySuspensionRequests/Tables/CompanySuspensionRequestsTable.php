<?php

namespace App\Filament\Resources\CompanySuspensionRequests\Tables;

use App\Actions\Company\ApproveCompanySuspension;
use App\Models\CompanySuspensionRequest;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class CompanySuspensionRequestsTable
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
                    ->modalDescription('This suspends the company. You must be a different staff member than the one who requested this.')
                    ->visible(fn (CompanySuspensionRequest $record): bool => $record->isPending()
                        && (int) $record->requested_by !== (int) auth()->id())
                    ->action(function (CompanySuspensionRequest $record): void {
                        try {
                            app(ApproveCompanySuspension::class)->execute($record, auth()->user());
                            Notification::make()->title('Suspension approved')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title('Could not approve')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
                        }
                    }),

                Action::make('rejectRequest')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (CompanySuspensionRequest $record): bool => $record->isPending()
                        && (int) $record->requested_by !== (int) auth()->id())
                    ->action(function (CompanySuspensionRequest $record): void {
                        $record->update(['status' => 'rejected', 'approved_by' => auth()->id()]);
                        Notification::make()->title('Suspension request rejected')->success()->send();
                    }),
            ]);
    }
}
