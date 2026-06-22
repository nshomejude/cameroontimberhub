<?php

namespace App\Filament\Exporter\Resources\Companies\Tables;

use App\Enums\CompanyStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('legal_name')->label('Company'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CompanyStatus $state): string => $state->label())
                    ->color(fn (CompanyStatus $state): string => $state->color()),
            ])
            ->recordActions([
                EditAction::make()->label('Edit profile'),
            ]);
    }
}
