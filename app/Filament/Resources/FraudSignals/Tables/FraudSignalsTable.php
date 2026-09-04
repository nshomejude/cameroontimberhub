<?php

namespace App\Filament\Resources\FraudSignals\Tables;

use App\Enums\FraudSignalStatus;
use App\Enums\FraudSignalType;
use App\Models\FraudSignal;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FraudSignalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('subject_type')
                    ->label('Subject type')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->searchable(),
                TextColumn::make('subject_id')->label('Subject ID'),
                TextColumn::make('signal_type')
                    ->label('Signal')
                    ->formatStateUsing(fn (?FraudSignalType $state): string => $state?->label() ?? '—')
                    ->badge(),
                TextColumn::make('severity')->badge()->color(fn ($state) => $state?->color()),
                TextColumn::make('status')->badge()->color(fn ($state) => $state?->color()),
                TextColumn::make('reviewedBy.name')->label('Reviewed by')->placeholder('—'),
                TextColumn::make('reviewed_at')->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(
                    collect(FraudSignalStatus::cases())->mapWithKeys(fn (FraudSignalStatus $s) => [$s->value => $s->label()])->all()
                ),
                SelectFilter::make('signal_type')->label('Signal')->options(
                    collect(FraudSignalType::cases())->mapWithKeys(fn (FraudSignalType $t) => [$t->value => $t->label()])->all()
                ),
            ])
            ->recordActions([
                Action::make('markConfirmed')
                    ->label('Confirm')
                    ->color('danger')
                    ->visible(fn (FraudSignal $record) => $record->status === FraudSignalStatus::Open)
                    ->requiresConfirmation()
                    ->action(function (FraudSignal $record): void {
                        $record->markReviewed(auth()->user(), FraudSignalStatus::Confirmed);
                        Notification::make()->title('Signal marked confirmed')->success()->send();
                    }),
                Action::make('markDismissed')
                    ->label('Dismiss')
                    ->color('gray')
                    ->visible(fn (FraudSignal $record) => $record->status === FraudSignalStatus::Open)
                    ->requiresConfirmation()
                    ->action(function (FraudSignal $record): void {
                        $record->markReviewed(auth()->user(), FraudSignalStatus::Dismissed);
                        Notification::make()->title('Signal dismissed')->success()->send();
                    }),
            ]);
    }
}
