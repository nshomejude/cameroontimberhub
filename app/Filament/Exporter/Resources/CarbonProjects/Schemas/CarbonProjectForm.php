<?php

namespace App\Filament\Exporter\Resources\CarbonProjects\Schemas;

use App\Enums\CarbonRegistryStatus;
use App\Enums\ProductStatus;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

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
                            ->label('Listing visibility')
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

                Section::make('Registry')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('public_id_display')
                            ->label('Public registry ID')
                            ->content(fn ($record) => $record?->public_id ?? 'Assigned on creation'),

                        Placeholder::make('registry_status_display')
                            ->label('Registry status')
                            ->content(fn ($record) => $record?->registry_status?->label() ?? CarbonRegistryStatus::Draft->label()),

                        Placeholder::make('verify_link')
                            ->label('Public verification page')
                            ->visible(fn ($record) => $record?->public_id && $record?->registry_status?->isPubliclyVerifiable())
                            ->content(fn ($record) => new HtmlString(
                                '<a class="text-primary-600 underline" target="_blank" href="'
                                .e(route('carbon.verify', $record->public_id)).'">'
                                .e(route('carbon.verify', $record->public_id)).'</a>'
                            ))
                            ->columnSpanFull(),

                        Textarea::make('boundary')
                            ->label('Project boundary (GeoJSON polygon)')
                            ->rows(6)
                            ->columnSpanFull()
                            ->helperText('Paste a GeoJSON Polygon, e.g. {"type":"Polygon","coordinates":[[[x,y],...]]}. Invalid polygons are rejected on save.')
                            ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state) : $state)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null)
                            ->rule(static function () {
                                return static function (string $attribute, $value, \Closure $fail): void {
                                    if (blank($value)) {
                                        return;
                                    }
                                    $decoded = is_array($value) ? $value : json_decode((string) $value, true);
                                    if (! is_array($decoded) || ! \App\Support\GeoJsonPolygon::isValid($decoded)) {
                                        $fail('The project boundary must be a valid GeoJSON Polygon.');
                                    }
                                };
                            }),
                    ]),
            ]);
    }
}
