<?php

namespace App\Filament\Widgets;

use App\Domain\Identity\Queries\ListPendingVerificationsQuery;
use App\Enums\VerificationRequestStatus;
use App\Models\VerificationRequest;
use App\Support\Bus\QueryBus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PendingVerificationsWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('companies.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('messages.filament.widgets.pending_verifications_heading'))
            ->description(__('messages.filament.widgets.pending_verifications_desc'))
            ->query(
                fn (): Builder => app(QueryBus::class)->dispatch(new ListPendingVerificationsQuery())
            )
            ->columns([
                TextColumn::make('company.name')
                    ->label(__('messages.filament.widgets.company'))
                    ->searchable(query: fn (Builder $q, string $s) => $q->whereHas('company', fn ($c) => $c->where('legal_name', 'ilike', "%{$s}%")))
                    ->url(fn (VerificationRequest $r) => $r->company
                        ? \App\Filament\Resources\VerificationRequests\VerificationRequestResource::getUrl('edit', ['record' => $r])
                        : null),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (VerificationRequestStatus $state): string => match ($state) {
                        VerificationRequestStatus::Pending  => __('messages.filament.widgets.status_pending'),
                        VerificationRequestStatus::InReview => __('messages.filament.widgets.status_in_review'),
                        default                             => $state->value,
                    })
                    ->color(fn (VerificationRequestStatus $state): string => match ($state) {
                        VerificationRequestStatus::Pending  => 'warning',
                        VerificationRequestStatus::InReview => 'info',
                        default                             => 'gray',
                    }),
                TextColumn::make('assignedTo.name')->label(__('messages.filament.widgets.assigned_to'))->placeholder(__('messages.filament.widgets.unassigned')),
                TextColumn::make('created_at')->label(__('messages.filament.widgets.submitted'))->date('d M Y')->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->paginated([5, 10]);
    }
}
