<?php

namespace App\Filament\Resources\Inspections\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InspectionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Inspection')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('inspector.user.name')->label('Inspector'),
                        TextEntry::make('timberLot.lot_number')->label('Timber lot')->placeholder('—'),
                        TextEntry::make('order_id')->label('Order ID')->placeholder('—'),
                        TextEntry::make('inspection_type')->badge(),
                        TextEntry::make('scheduled_for')->date(),
                        TextEntry::make('performed_at')->dateTime(),
                        TextEntry::make('location')->placeholder('—'),
                        TextEntry::make('observed_quantity')->placeholder('—'),
                        TextEntry::make('result')->badge()->placeholder('—'),
                        TextEntry::make('finalised_at')->dateTime()->placeholder('Not finalised'),
                        TextEntry::make('digital_signature')
                            ->label('Digital signature')
                            ->placeholder('—')
                            ->limit(24)
                            ->columnSpanFull(),
                    ]),
                Section::make('Findings')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('quality_findings')->placeholder('—'),
                        TextEntry::make('species_findings')->placeholder('—'),
                        TextEntry::make('packaging_findings')->placeholder('—'),
                        TextEntry::make('inspector_notes')->placeholder('—'),
                    ]),
                Section::make('Amendments')
                    ->schema([
                        RepeatableEntry::make('amendments')
                            ->schema([
                                TextEntry::make('amendedBy.name')->label('Amended by'),
                                TextEntry::make('reason'),
                                TextEntry::make('created_at')->dateTime(),
                            ])
                            ->columns(3),
                    ])
                    ->collapsed(fn ($record) => $record->amendments->isEmpty()),
            ]);
    }
}
