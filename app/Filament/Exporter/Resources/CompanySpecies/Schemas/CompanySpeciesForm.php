<?php

namespace App\Filament\Exporter\Resources\CompanySpecies\Schemas;

use App\Enums\PriceBasis;
use App\Enums\RfqCurrency;
use App\Models\Species;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanySpeciesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Species handled')
                    ->columns(2)
                    ->schema([
                        Select::make('species_id')
                            ->label('Species')
                            ->options(fn () => Species::query()->published()->orderBy('common_name')->pluck('common_name', 'id'))
                            ->searchable()
                            ->required()
                            ->columnSpanFull(),

                        TextInput::make('moisture_content')
                            ->label('Moisture content')
                            ->placeholder('e.g. Kiln dried, 12%')
                            ->maxLength(60),

                        TextInput::make('dimensions')
                            ->placeholder('e.g. 50mm x 150mm x 3000mm')
                            ->maxLength(255),

                        TextInput::make('unit')
                            ->placeholder('m3, m2, pcs, ton')
                            ->maxLength(10),

                        Select::make('basis')
                            ->options(collect(PriceBasis::cases())->mapWithKeys(fn (PriceBasis $b) => [$b->value => $b->label()])->all()),

                        TextInput::make('region')
                            ->placeholder('e.g. Douala, Yaoundé')
                            ->maxLength(120),

                        TextInput::make('price_amount')
                            ->label('Indicative price')
                            ->numeric()
                            ->minValue(0),

                        Select::make('price_currency')
                            ->label('Currency')
                            ->options(collect(RfqCurrency::cases())->mapWithKeys(fn (RfqCurrency $c) => [$c->value => $c->value])->all()),
                    ]),
            ]);
    }
}
