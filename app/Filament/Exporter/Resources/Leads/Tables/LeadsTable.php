<?php

namespace App\Filament\Exporter\Resources\Leads\Tables;

use App\Enums\LeadStatus;
use App\Enums\RfqType;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('buyer_name')->label('Buyer')->searchable()->placeholder('—'),
                TextColumn::make('source')->badge()->color('gray'),
                // RFQ-originated leads carry the RFQ's flow (export /
                // manufacturing / transport); inquiry leads have no RFQ.
                TextColumn::make('rfq.type')->label('RFQ type')->badge()->color('info')
                    ->formatStateUsing(fn (?RfqType $state): string => $state?->label() ?? '—')
                    ->placeholder('—'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (LeadStatus $state): string => $state->label())
                    ->color(fn (LeadStatus $state): string => $state->color()),
                TextColumn::make('buyer_country_code')->label('Country')->placeholder('—'),
                TextColumn::make('last_activity_at')->label('Activity')->dateTime('d M Y')->sortable(),
                TextColumn::make('created_at')->label('Received')->date('d M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(LeadStatus::cases())->mapWithKeys(fn (LeadStatus $s) => [$s->value => $s->label()])->all()),
                SelectFilter::make('rfq_type')->label('RFQ type')
                    ->options(collect(RfqType::cases())->mapWithKeys(fn (RfqType $t) => [$t->value => $t->label()])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('rfq', fn (Builder $r) => $r->where('type', $data['value']))
                        : $query),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make()->label('Open'),
            ]);
    }
}
