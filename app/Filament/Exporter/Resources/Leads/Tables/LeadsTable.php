<?php

namespace App\Filament\Exporter\Resources\Leads\Tables;

use App\Enums\LeadStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('buyer_name')->label('Buyer')->searchable()->placeholder('—'),
                TextColumn::make('source')->badge()->color('gray'),
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
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make()->label('Open'),
            ]);
    }
}
