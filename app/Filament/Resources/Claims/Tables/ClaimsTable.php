<?php

namespace App\Filament\Resources\Claims\Tables;

use App\Models\Claim;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClaimsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('claim_text')
                    ->label('Claim')
                    ->limit(60)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('page_location')->label('Location')->color('gray'),
                TextColumn::make('owner.name')->label('Owner')->color('gray'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'needs_review' => 'warning',
                        'retired' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('review_date')->date()->sortable(),
                TextColumn::make('attention')
                    ->label('Attention')
                    ->state(function (Claim $record): ?string {
                        $overdue = $record->status === 'approved'
                            && $record->review_date !== null
                            && $record->review_date->isPast();

                        return $record->status === 'needs_review' || $overdue ? 'Needs attention' : null;
                    })
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),
            ])
            ->defaultSort('review_date')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
