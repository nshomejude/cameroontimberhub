<?php

namespace App\Filament\Resources\TimberLots\RelationManagers;

use App\Enums\LotEventType;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Read-only view of a lot's hash-chained Traceability Event Ledger (blueprint §10). */
class LotEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'lotEvents';

    protected static ?string $title = 'Traceability events';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('event_type')
            ->columns([
                TextColumn::make('event_type')
                    ->badge()
                    ->formatStateUsing(fn (LotEventType $state): string => $state->label()),
                TextColumn::make('actor.name')->label('Actor')->placeholder('System'),
                TextColumn::make('occurred_at')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('location')->placeholder('—'),
                TextColumn::make('event_hash')->label('Hash')->limit(12)->fontFamily('mono'),
            ])
            ->defaultSort('id');
    }
}
