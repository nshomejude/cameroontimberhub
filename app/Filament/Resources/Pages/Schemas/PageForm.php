<?php

namespace App\Filament\Resources\Pages\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Page')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255)->live(onBlur: true)->columnSpanFull(),
                        TextInput::make('slug')->required()->maxLength(200)->unique(ignoreRecord: true),
                        Select::make('template')
                            ->options([
                                'static' => 'Static',
                                'landing' => 'Landing',
                                'programmatic' => 'Programmatic',
                                'legal' => 'Legal',
                            ])
                            ->default('static')
                            ->required(),
                        Toggle::make('is_published')->default(false)->columnSpanFull(),
                    ]),

                Section::make('SEO')
                    ->columns(1)
                    ->schema([
                        TextInput::make('h1')->maxLength(255)->label('H1 override')
                            ->helperText('Leave blank to use the page title.'),
                        Textarea::make('meta_description')->rows(2)->maxLength(320),
                        TextInput::make('canonical_url')->maxLength(512)->url(),
                        KeyValue::make('schema_json')->label('Schema.org JSON-LD')
                            ->helperText('Key/value pairs for structured data. Raw JSON may be set in data.schema_json.'),
                    ]),
            ]);
    }
}
