<?php

namespace App\Filament\Exporter\Resources\Capacities\Schemas;

use App\Http\Controllers\Public\TransformationNetworkController;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CapacityForm
{
    /**
     * Suggested vocabulary offered alongside TransformationNetworkController's
     * manufacturing capabilities -- logistics-specific terms a haulage or
     * warehousing company would use to describe what it offers.
     */
    private const LOGISTICS_TYPES = [
        'Trucking', 'Warehousing', 'Port handling', 'Container haulage', 'Freight forwarding',
    ];

    private const PERIODS = [
        'day' => 'Per day',
        'week' => 'Per week',
        'month' => 'Per month',
        'quarter' => 'Per quarter',
        'year' => 'Per year',
    ];

    public static function configure(Schema $schema): Schema
    {
        $suggestions = [...self::LOGISTICS_TYPES, ...TransformationNetworkController::BUSINESS_TYPES];

        return $schema
            ->components([
                Section::make('Capacity')
                    ->columns(2)
                    ->schema([
                        Select::make('capability')
                            ->label('Capability')
                            ->options(array_combine($suggestions, $suggestions))
                            ->searchable()
                            ->allowHtml(false)
                            ->createOptionForm([
                                TextInput::make('value')->required(),
                            ])
                            ->createOptionUsing(fn (array $data): string => $data['value'])
                            ->helperText('E.g. "Trucking — Douala to Yaoundé corridor". Pick a suggestion or type your own.')
                            ->required()
                            ->columnSpanFull(),

                        TextInput::make('quantity')
                            ->numeric()
                            ->minValue(0.01)
                            ->required(),

                        TextInput::make('unit')
                            ->placeholder('m3, ton, TEU, trips')
                            ->required(),

                        Select::make('period')
                            ->options(self::PERIODS)
                            ->required(),
                    ]),
            ]);
    }
}
