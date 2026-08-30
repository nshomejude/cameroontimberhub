<?php

namespace App\Filament\Resources\Inspectors\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InspectorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('organisation_name')
                    ->label('Organisation')
                    ->placeholder('Independent')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                IconColumn::make('identity_verified_at')
                    ->label('ID verified')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->identity_verified_at !== null),
                IconColumn::make('agreement_accepted_at')
                    ->label('Agreement')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->agreement_accepted_at !== null),
                TextColumn::make('years_experience')
                    ->label('Experience (yrs)')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'active' => 'Active',
                        'suspended' => 'Suspended',
                        'deactivated' => 'Deactivated',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
