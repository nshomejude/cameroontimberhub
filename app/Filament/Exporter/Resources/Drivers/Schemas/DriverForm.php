<?php

namespace App\Filament\Exporter\Resources\Drivers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DriverForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Driver')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Full name')
                            ->required()
                            ->maxLength(150),

                        TextInput::make('license_number')
                            ->label('Driving licence number')
                            ->required()
                            ->maxLength(60),

                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(40),

                        Toggle::make('is_active')
                            ->label('Currently driving')
                            ->default(true),
                    ]),
            ]);
    }
}
