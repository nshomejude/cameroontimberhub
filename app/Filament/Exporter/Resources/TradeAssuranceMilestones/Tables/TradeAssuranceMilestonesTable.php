<?php

namespace App\Filament\Exporter\Resources\TradeAssuranceMilestones\Tables;

use App\Enums\TradeAssuranceMilestoneStatus;
use App\Models\TradeAssuranceMilestone;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TradeAssuranceMilestonesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('agreement.order.reference_code')
                    ->label('Order')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sequence')->label('#')->sortable(),
                TextColumn::make('title')->searchable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (TradeAssuranceMilestoneStatus $state): string => $state->label())
                    ->color(fn (TradeAssuranceMilestoneStatus $state): string => match ($state) {
                        TradeAssuranceMilestoneStatus::Pending => 'gray',
                        TradeAssuranceMilestoneStatus::InProgress => 'warning',
                        TradeAssuranceMilestoneStatus::BuyerConfirmed => 'success',
                        TradeAssuranceMilestoneStatus::Disputed => 'danger',
                        TradeAssuranceMilestoneStatus::Released => 'success',
                    }),
                TextColumn::make('expected_completion_date')->label('Expected')->date('d M Y')->placeholder('—'),
                TextColumn::make('amount_share_percent')->label('Share')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : $state.'%')
                    ->tooltip('Informational only — not wired to any payment.'),
                TextColumn::make('confirmed_at')->label('Buyer confirmed')->dateTime('d M Y H:i')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(TradeAssuranceMilestoneStatus::cases())
                        ->mapWithKeys(fn (TradeAssuranceMilestoneStatus $s) => [$s->value => $s->label()])
                        ->all()
                ),
            ])
            ->defaultSort('sequence')
            ->emptyStateHeading('No Trade Assurance milestones yet')
            ->emptyStateDescription('Milestones appear here once a Trade Assurance agreement exists for one of your orders.')
            ->recordActions([
                Action::make('markInProgress')
                    ->label('Mark in progress')
                    ->icon('heroicon-o-play-circle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (TradeAssuranceMilestone $record): bool => $record->status === TradeAssuranceMilestoneStatus::Pending)
                    ->action(function (TradeAssuranceMilestone $record): void {
                        $record->markInProgress();

                        Notification::make()->title('Milestone marked in progress')->success()->send();
                    }),
            ]);
    }
}
