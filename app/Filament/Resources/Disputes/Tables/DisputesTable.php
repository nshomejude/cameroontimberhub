<?php

namespace App\Filament\Resources\Disputes\Tables;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
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
                Action::make('resolve')
                    ->label('Resolve')
                    ->visible(fn (Dispute $record): bool => $record->status === DisputeStatus::UnderReview)
                    ->schema([
                        Textarea::make('resolution_notes')
                            ->label('Resolution notes')
                            ->required()
                            ->minLength(1),
                    ])
                    ->action(function (Dispute $record, array $data): void {
                        try {
                            $record->resolve(auth()->user(), $data['resolution_notes']);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Dispute resolved.')->send();
                    }),
                Action::make('close')
                    ->label('Close')
                    ->requiresConfirmation()
                    ->visible(fn (Dispute $record): bool => in_array($record->status, [DisputeStatus::Resolved, DisputeStatus::Appealed], true))
                    ->action(function (Dispute $record): void {
                        try {
                            $record->close(auth()->user());
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Dispute closed.')->send();
                    }),
            ]);
    }
}
