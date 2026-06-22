<?php

namespace App\Filament\Resources\Species\Schemas;

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

                Section::make('Classification')
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
