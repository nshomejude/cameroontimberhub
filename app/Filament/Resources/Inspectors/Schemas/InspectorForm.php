<?php

namespace App\Filament\Resources\Inspectors\Schemas;

use App\Models\User;
use App\Support\CameroonGeography;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InspectorForm
{
    public const INSPECTION_TYPES = [
        'pre_production' => 'Pre-production inspection',
        'pre_shipment' => 'Pre-shipment inspection',
        'loading' => 'Loading inspection',
        'quantity' => 'Quantity inspection',
        'moisture' => 'Moisture inspection',
        'species_identification' => 'Species identification',
        'quality_grade' => 'Quality/grade inspection',
        'packaging' => 'Packaging inspection',
        'document_cross_check' => 'Document cross-check',
        'site' => 'Site inspection',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Inspector profile')
                    ->columns(2)
                    ->schema([
                        Select::make('user_id')
                            ->label('User')
                            ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        TextInput::make('organisation_name')
                            ->label('Organisation')
                            ->maxLength(255),
                        TextInput::make('years_experience')
                            ->numeric()
                            ->minValue(0),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'active' => 'Active',
                                'suspended' => 'Suspended',
                                'deactivated' => 'Deactivated',
                            ])
                            ->required()
                            ->default('pending'),
                        DateTimePicker::make('identity_verified_at')
                            ->label('Identity verified at'),
                        DateTimePicker::make('agreement_accepted_at')
                            ->label('Agreement accepted at'),
                        Select::make('coverage_regions')
                            ->label('Coverage regions')
                            ->multiple()
                            ->options(array_combine(CameroonGeography::regionNames(), CameroonGeography::regionNames()))
                            ->columnSpanFull(),
                        Select::make('inspection_categories')
                            ->label('Qualified inspection categories')
                            ->multiple()
                            ->options(self::INSPECTION_TYPES)
                            ->columnSpanFull(),
                        TagsInput::make('professional_credentials')
                            ->label('Professional credentials')
                            ->helperText('One credential description/reference per tag.')
                            ->columnSpanFull(),
                        Textarea::make('conflict_of_interest_declaration')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
