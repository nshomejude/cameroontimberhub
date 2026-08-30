<?php

namespace App\Filament\Resources\LotTransformations\Schemas;

use App\Models\Company;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Simple staff-facing form for the mass-balance ledger. Line-item
 * input/output lot linking (with automatic total computation) is handled
 * via App\Models\LotTransformation::recordFor() — this form covers
 * direct editing of an already-recorded transformation's headline fields.
 */
class LotTransformationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Transformation')
                    ->columns(2)
                    ->schema([
                        Select::make('processor_company_id')
                            ->label('Processor')
                            ->options(fn () => Company::query()->orderBy('legal_name')->pluck('legal_name', 'id'))
                            ->searchable()
                            ->required(),
                        TextInput::make('transformation_type')
                            ->label('Transformation type')
                            ->placeholder('sawing, kiln_drying, planing, grading…')
                            ->required()
                            ->maxLength(60),
                        DateTimePicker::make('processed_at')
                            ->required()
                            ->default(now()),
                        TextInput::make('input_volume_m3')
                            ->label('Input volume (m³)')
                            ->numeric()
                            ->required(),
                        TextInput::make('output_volume_m3')
                            ->label('Output volume (m³)')
                            ->numeric()
                            ->required(),
                        TextInput::make('loss_volume_m3')
                            ->label('Loss volume (m³)')
                            ->numeric()
                            ->required()
                            ->helperText('Stored explicitly — usually input minus output, but not always assumed to balance exactly.'),
                        TextInput::make('transformation_ratio')
                            ->label('Ratio (output/input)')
                            ->numeric()
                            ->helperText('Leave blank to let the mass-balance service compute it.'),
                        Textarea::make('notes')->rows(3)->columnSpanFull(),
                    ]),
            ]);
    }
}
