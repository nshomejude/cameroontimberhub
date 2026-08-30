<?php

namespace App\Filament\Resources\RegulatorySources\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RegulatorySourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Regulatory source')
                    ->columns(2)
                    ->schema([
                        TextInput::make('authority')->required()->maxLength(255),
                        TextInput::make('instrument_name')->required()->maxLength(255),
                        TextInput::make('jurisdiction')->required()->maxLength(120),
                        TextInput::make('reference_number')->maxLength(100),
                        TextInput::make('official_url')->url()->maxLength(500)->columnSpanFull(),
                        DatePicker::make('effective_date'),
                        DatePicker::make('last_checked_at'),
                        DatePicker::make('next_review_date'),
                        Select::make('legal_review_status')
                            ->options([
                                'pending' => 'Pending',
                                'reviewed' => 'Reviewed',
                                'needs_update' => 'Needs update',
                            ])
                            ->default('pending')
                            ->required(),
                        Select::make('owner_id')
                            ->relationship('owner', 'name')
                            ->searchable()
                            ->preload()
                            ->label('Owner'),
                        Textarea::make('summary')->rows(3)->columnSpanFull(),
                        Textarea::make('internal_interpretation')->rows(3)->columnSpanFull(),
                    ]),
            ]);
    }
}
