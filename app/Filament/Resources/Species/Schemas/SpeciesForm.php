<?php

namespace App\Filament\Resources\Species\Schemas;

use App\Enums\LogExportStatus;
use App\Enums\TimberCategory;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SpeciesForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Core')
                    ->columns(2)
                    ->schema([
                        TextInput::make('common_name')->required()->maxLength(150),
                        TextInput::make('scientific_name')->maxLength(180),
                        TextInput::make('slug')
                            ->maxLength(180)
                            ->unique(ignoreRecord: true)
                            ->helperText('Leave blank to auto-generate from the common name.'),
                        TextInput::make('family')->maxLength(120),
                        TagsInput::make('local_names')->placeholder('Add a name')->columnSpanFull(),
                        TagsInput::make('trade_names')->placeholder('Add a name')->columnSpanFull(),
                    ]),

                Section::make('Content')
                    ->schema([
                        Textarea::make('description')->rows(6)->columnSpanFull(),
                        KeyValue::make('characteristics')
                            ->keyLabel('Property')->valueLabel('Value')
                            ->helperText('e.g. Density, Durability, Common uses, Colour.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Cameroon classification')
                    ->description('Commercial/market grouping — not a MINFOF tax category or any other legal classification.')
                    ->columns(2)
                    ->schema([
                        Select::make('commercial_category')
                            ->label('Commercial category')
                            ->options(TimberCategory::options())
                            ->native(false),
                        Toggle::make('is_promoted')
                            ->label('Promoted species (essence de promotion)')
                            ->helperText('A lesser-known species promoted to broaden the harvest.'),
                        Select::make('log_export_status')
                            ->label('Log export status')
                            ->options(LogExportStatus::options())
                            ->default(LogExportStatus::Unknown->value)
                            ->native(false)
                            ->helperText('Informational only. Verify against current MINFOF publications before relying on it.'),
                        TagsInput::make('region_availability')
                            ->label('Regions harvested')
                            ->placeholder('East, South, Centre…'),
                    ]),

                Section::make('Technical properties')
                    ->columns(2)
                    ->schema([
                        TextInput::make('density_kg_m3_min')->label('Density min (kg/m³)')->numeric()->minValue(0),
                        TextInput::make('density_kg_m3_max')->label('Density max (kg/m³)')->numeric()->minValue(0),
                        TextInput::make('durability_class')->label('Durability class')->maxLength(60)->placeholder('Class 1 (Very Durable)'),
                        TextInput::make('janka_hardness')->label('Janka hardness (N)')->numeric()->minValue(0),
                        TagsInput::make('typical_uses')->label('Typical uses')->placeholder('Add a use')->columnSpanFull(),
                    ]),

                Section::make('CITES')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_cites_listed')->live(),
                        Select::make('cites_appendix')
                            ->options(['I' => 'I', 'II' => 'II', 'III' => 'III'])
                            ->visible(fn (callable $get): bool => (bool) $get('is_cites_listed')),
                    ]),

                Section::make('Media')
                    ->schema([
                        FileUpload::make('image_path')
                            ->image()->imageEditor()
                            ->disk('public')->directory('species')
                            ->columnSpanFull(),
                    ]),

                Section::make('SEO & visibility')
                    ->columns(2)
                    ->schema([
                        TextInput::make('meta_title')->maxLength(255),
                        TextInput::make('sort_order')->numeric()->default(0),
                        Textarea::make('meta_description')->rows(2)->maxLength(320)->columnSpanFull(),
                        Toggle::make('is_published')->default(true),
                    ]),
            ]);
    }
}
