<?php

namespace App\Filament\Exporter\Resources\Quotes\Tables;

use App\Enums\ConversationTopic;
use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use App\Services\QuoteService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;

class QuotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_code')->label('Quote')->searchable(),
                TextColumn::make('rfq.reference_code')->label('Request')->searchable(),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (QuoteStatus $state): string => $state->label())
                    ->color(fn (QuoteStatus $state): string => $state->color()),
                TextColumn::make('total_amount')->label('Total')
                    ->formatStateUsing(fn ($state, Quote $record): string => $record->money($state))
                    ->sortable(),
                TextColumn::make('lead_time_days')->label('Lead time')->suffix(' days')->placeholder('—'),
                TextColumn::make('valid_until')->label('Valid until')->date('d M Y')->placeholder('—')->sortable(),
                TextColumn::make('submitted_at')->label('Submitted')->dateTime('d M Y')->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(QuoteStatus::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),

                Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-m-paper-airplane')
                    ->requiresConfirmation()
                    ->modalDescription('The buyer will be emailed and can then accept or decline. Totals are recalculated from your line items.')
                    ->visible(fn (Quote $record): bool => $record->status === QuoteStatus::Draft)
                    ->action(function (Quote $record, QuoteService $quotes) {
                        try {
                            $quotes->submit($record, auth()->user());
                            Notification::make()->title('Quote submitted')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                // Supplier-side parity for the mockup's quotation card: the
                // supplier is the only party who may put a quotation into a
                // conversation, and this is where they do it.
                //
                // Only offered when the RFQ actually belongs to a registered
                // account — a guest RFQ has no buyer to open a thread with, and
                // inventing one would be worse than hiding the button.
                Action::make('shareInChat')
                    ->label('Share in chat')
                    ->icon('heroicon-m-chat-bubble-left-right')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Posts this quotation into your conversation with the buyer, where they can accept, decline or counter it. Starts the conversation if there is not one already.')
                    ->visible(fn (Quote $record): bool => in_array($record->status, [QuoteStatus::Submitted, QuoteStatus::Viewed], true)
                        && $record->rfq?->user_id !== null)
                    ->action(function (Quote $record, MessagingService $messaging, ChatCommerceService $commerce) {
                        try {
                            $record->loadMissing(['rfq.user', 'company']);

                            $conversation = $messaging->start(
                                buyer: $record->rfq->user,
                                company: $record->company,
                                topic: ConversationTopic::Rfq,
                                subject: 'Quotation '.$record->reference_code,
                            );

                            $commerce->issueQuotation($conversation, $record, auth()->user());

                            Notification::make()->title('Quotation shared in the conversation')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('withdraw')
                    ->label('Withdraw')
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('The buyer will no longer see this quote. You may then quote this request again.')
                    ->visible(fn (Quote $record): bool => in_array($record->status, [QuoteStatus::Draft, QuoteStatus::Submitted, QuoteStatus::Viewed], true))
                    ->action(function (Quote $record, QuoteService $quotes) {
                        try {
                            $quotes->withdraw($record, auth()->user());
                            Notification::make()->title('Quote withdrawn')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
