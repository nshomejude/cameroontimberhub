<?php

namespace App\Filament\Resources\SupportTickets\Tables;

use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupportTicketsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'order']))
            ->columns([
                TextColumn::make('reference')->searchable()->copyable(),
                TextColumn::make('subject')->searchable()->limit(50),
                TextColumn::make('category')->badge()
                    ->formatStateUsing(fn (SupportTicketCategory $state) => $state->label()),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (SupportTicketStatus $state) => $state->label())
                    ->color(fn (SupportTicketStatus $state) => $state->color()),
                TextColumn::make('user.name')->label('Requester')->searchable()->placeholder('—'),
                TextColumn::make('order.reference_code')->label('Order')->placeholder('—'),
                TextColumn::make('last_activity_at')->label('Last activity')->dateTime()->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('last_activity_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(SupportTicketStatus::options()),
                SelectFilter::make('category')->options(SupportTicketCategory::options()),
            ])
            ->recordActions([
                Action::make('thread')
                    ->label('View thread')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->modalHeading(fn (SupportTicket $record) => $record->reference.' — '.$record->subject)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (SupportTicket $record) => view('filament.support.thread', [
                        'ticket' => $record->load('messages.user'),
                    ])),
                Action::make('reply')
                    ->label('Reply')
                    ->icon('heroicon-o-paper-airplane')
                    ->schema([
                        Textarea::make('body')->label('Message')->required()->maxLength(5000)->rows(6),
                        Select::make('status')
                            ->label('Set status')
                            ->options([
                                'pending' => SupportTicketStatus::Pending->label(),
                                'resolved' => SupportTicketStatus::Resolved->label(),
                                'closed' => SupportTicketStatus::Closed->label(),
                            ])
                            ->default('pending')
                            ->required(),
                    ])
                    ->action(function (SupportTicket $record, array $data): void {
                        app(SupportTicketService::class)->staffReply(
                            $record,
                            auth()->user(),
                            $data['body'],
                            SupportTicketStatus::from($data['status']),
                        );

                        Notification::make()->success()->title('Reply sent.')->send();
                    }),
                Action::make('status')
                    ->label('Change status')
                    ->icon('heroicon-o-arrow-path')
                    ->schema([
                        Select::make('status')->options(SupportTicketStatus::options())->required(),
                    ])
                    ->fillForm(fn (SupportTicket $record) => ['status' => $record->status->value])
                    ->action(function (SupportTicket $record, array $data): void {
                        app(SupportTicketService::class)->changeStatus($record, SupportTicketStatus::from($data['status']));

                        Notification::make()->success()->title('Status updated.')->send();
                    }),
            ]);
    }
}
