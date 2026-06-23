<?php

namespace App\Filament\Widgets;

use App\Enums\VerificationRequestStatus;
use App\Models\VerificationRequest;
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
            ->heading('Pending verifications')
            ->description('Verification requests awaiting review')
            ->query(
                fn (): Builder => VerificationRequest::open()
                    ->with('company:id,legal_name,trade_name,slug,status')
                    ->with('assignedTo:id,name')
                    ->latest()
            )
            ->columns([
                TextColumn::make('company.name')
                    ->label('Company')
                    ->searchable(query: fn (Builder $q, string $s) => $q->whereHas('company', fn ($c) => $c->where('legal_name', 'ilike', "%{$s}%")))
                    ->url(fn (VerificationRequest $r) => $r->company
                        ? \App\Filament\Resources\VerificationRequests\VerificationRequestResource::getUrl('edit', ['record' => $r])
                        : null),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (VerificationRequestStatus $state): string => match ($state) {
                        VerificationRequestStatus::Pending  => 'Pending',
                        VerificationRequestStatus::InReview => 'In review',
                        default                             => $state->value,
                    })
                    ->color(fn (VerificationRequestStatus $state): string => match ($state) {
                        VerificationRequestStatus::Pending  => 'warning',
                        VerificationRequestStatus::InReview => 'info',
                        default                             => 'gray',
                    }),
                TextColumn::make('assignedTo.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('created_at')->label('Submitted')->date('d M Y')->sortable(),
            ])
            ->defaultSort('created_at', 'asc')
            ->paginated([5, 10]);
    }
}
