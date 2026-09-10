<?php

namespace App\Filament\Exporter\Resources\Vehicles\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VehicleForm
{
    private const TYPES = [
        'truck' => 'Truck',
        'trailer' => 'Trailer',
        'pickup' => 'Pickup',
        'van' => 'Van',
        'flatbed' => 'Flatbed',
        'container_chassis' => 'Container chassis',
        'other' => 'Other',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Vehicle')
                    ->columns(2)
                    ->schema([
                        TextInput::make('registration_number')
                            ->label('Registration / plate number')
                            ->required()
                            ->maxLength(40),

                        Select::make('type')
                            ->options(self::TYPES)
                            ->required(),

                        TextInput::make('capacity_tonnes')
                            ->label('Payload capacity (tonnes)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01),

                        Toggle::make('is_active')
                            ->label('In service')
                            ->default(true),
                    ]),
            ]);
    }
}
