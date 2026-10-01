<?php

namespace App\Filament\Resources\Disputes\Tables;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Notifications\DisputeResolvedNotification;
use App\Services\Commission\CommissionCalculator;
use App\Services\DisputeNotifier;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;

class DisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('order_id')->label('Order')->sortable(),
                TextColumn::make('category')->badge(),
                TextColumn::make('status')->badge()->color(fn (DisputeStatus $state) => $state->color()),
                TextColumn::make('raisedByUser.name')->label('Raised by')->placeholder('—'),
                TextColumn::make('respondentCompany.name')->label('Respondent')->placeholder('—'),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('resolved_at')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(DisputeStatus::options()),
            ])
            ->recordActions([
                // Take a stalled case into review, or re-review an appeal.
                Action::make('moveToReview')
                    ->label(fn (Dispute $record): string => $record->status === DisputeStatus::Appealed ? 'Re-review appeal' : 'Move to review')
                    ->icon('heroicon-o-magnifying-glass')
                    ->requiresConfirmation()
                    ->visible(fn (Dispute $record): bool => in_array($record->status, Dispute::REVIEWABLE_STATUSES, true))
                    ->action(function (Dispute $record): void {
                        static::attempt(fn () => $record->moveToReview(auth()->user()), 'Dispute moved to review.');
                    }),
                Action::make('resolve')
                    ->label('Resolve')
                    ->visible(fn (Dispute $record): bool => in_array($record->status, Dispute::RESOLVABLE_STATUSES, true))
                    ->schema([
                        Textarea::make('resolution_notes')
                            ->label('Resolution notes')
                            ->helperText('Sent to both parties.')
                            ->required()
                            ->minLength(1),
                        // PRICING_SPEC §15: commission treatment on a dispute
                        // is decided here and recorded on the order.
                        TextInput::make('commission_credit')
                            ->label('Credit back marketplace commission')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(fn (Dispute $record): float => (float) app(CommissionCalculator::class)->creditableAmount($record->order))
                            ->prefix(fn (Dispute $record): ?string => $record->order?->currency?->value)
                            ->helperText(fn (Dispute $record): string => 'Optional. Up to '
                                .app(CommissionCalculator::class)->creditableAmount($record->order)
                                .' still creditable on this order. Leave blank for no commission credit.')
                            ->visible(fn (Dispute $record): bool => (bool) $record->order?->is_commission_charged),
                    ])
                    ->action(function (Dispute $record, array $data): void {
                        // A resolve after an appeal (appealed_at set) is the appeal decision.
                        $event = $record->appealed_at !== null
                            ? DisputeResolvedNotification::EVENT_APPEAL_DECIDED
                            : DisputeResolvedNotification::EVENT_RESOLVED;

                        if (static::attempt(fn () => $record->resolve(auth()->user(), $data['resolution_notes'], $data['commission_credit'] ?? null), 'Dispute resolved.')) {
                            app(DisputeNotifier::class)->decided($record, $event);
                        }
                    }),
                Action::make('close')
                    ->label('Close')
                    ->requiresConfirmation()
                    ->visible(fn (Dispute $record): bool => in_array($record->status, [DisputeStatus::Resolved, DisputeStatus::Appealed], true))
                    ->action(function (Dispute $record): void {
                        if (static::attempt(fn () => $record->close(auth()->user()), 'Dispute closed.')) {
                            app(DisputeNotifier::class)->decided($record, DisputeResolvedNotification::EVENT_CLOSED);
                        }
                    }),
            ]);
    }

    /** Run a lifecycle transition, surfacing a refused transition as a danger toast. */
    protected static function attempt(callable $transition, string $success): bool
    {
        try {
            $transition();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return false;
        }

        Notification::make()->success()->title($success)->send();

        return true;
    }
}
