<?php

namespace App\Filament\Exporter\Resources\CarbonProjects\Schemas;

use App\Enums\ProductStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CarbonProjectForm
{
    private const PROJECT_TYPES = [
        'reforestation' => 'Reforestation',
        'afforestation' => 'Afforestation',
        'avoided_deforestation' => 'Avoided deforestation',
        'agroforestry' => 'Agroforestry',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Carbon project')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->columnSpanFull(),

                        Select::make('project_type')
                            ->options(self::PROJECT_TYPES)
                            ->required(),

                        Select::make('status')
                            ->options(ProductStatus::options())
                            ->default(ProductStatus::Draft->value)
                            ->required(),

                        TextInput::make('region')->label('Region / location'),

                        TextInput::make('area_hectares')
                            ->label('Area (hectares)')
                            ->numeric()
                            ->minValue(0),

                        TextInput::make('estimated_credits_per_year')
                            ->label('Estimated credits per year (tCO2e)')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Informational estimate only — not a traded or verified credit figure.'),

                        Textarea::make('description')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
