<?php

namespace App\Filament\Widgets;

use App\Enums\RfqStatus;
use App\Models\Rfq;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RfqTriageWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('rfqs.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('RFQs to triage')
            ->description('Email-verified requests awaiting admin action')
            ->query(
                fn (): Builder => Rfq::verified()
                    ->whereIn('status', [RfqStatus::New->value, RfqStatus::InReview->value])
                    ->latest()
            )
            ->columns([
                TextColumn::make('reference_code')->label('Ref')->searchable()->copyable(),
                TextColumn::make('buyer_name')
                    ->description(fn (Rfq $r): string => implode(' · ', array_filter([$r->buyer_company, $r->buyer_country_code])))
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RfqStatus $state): string => $state->label())
                    ->color(fn (RfqStatus $state): string => $state->color()),
                TextColumn::make('spam_score')
                    ->label('Spam')
                    ->badge()
                    ->color(fn (int $state): string => $state >= 70 ? 'danger' : ($state >= 30 ? 'warning' : 'gray')),
                TextColumn::make('created_at')->label('Received')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([RfqStatus::New->value => 'New', RfqStatus::InReview->value => 'In review']),
            ])
            ->defaultSort('created_at', 'asc')
            ->paginated([5, 10])
            ->recordUrl(fn (Rfq $r) => \App\Filament\Resources\Rfqs\RfqResource::getUrl('edit', ['record' => $r]));
    }
}
