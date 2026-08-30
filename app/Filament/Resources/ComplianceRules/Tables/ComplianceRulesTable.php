<?php

namespace App\Filament\Resources\ComplianceRules\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ComplianceRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('regulatorySource.instrument_name')->label('Source')->searchable()->sortable(),
                TextColumn::make('regulatory_framework')->searchable()->sortable(),
                TextColumn::make('country_code')->badge()->color('gray'),
                TextColumn::make('product_category'),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('review_date')->date()->sortable(),
            ])
            ->defaultSort('regulatory_framework')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
