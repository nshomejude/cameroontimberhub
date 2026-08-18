<?php

namespace App\Filament\Resources\Pages\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PageForm
{
    /** Icon names offered for the About/Contact pillar strip (Heroicons v2 outline). */
    private const ICONS = [
        'shield-check' => 'Shield (trust)',
        'sparkles' => 'Sparkles (sustainability)',
        'globe-alt' => 'Globe (global reach)',
        'users' => 'People (community)',
        'lifebuoy' => 'Lifebuoy (support)',
        'clock' => 'Clock (response time)',
        'check-badge' => 'Badge (verified)',
        'building-office-2' => 'Building (offices)',
    ];

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
                                'about' => 'About (marketing layout)',
                                'landing' => 'Landing',
                                'programmatic' => 'Programmatic',
                                'legal' => 'Legal',
                            ])
                            ->default('static')
                            ->live()
                            ->required(),
                        Toggle::make('is_published')->default(false)->columnSpanFull(),
                    ]),

                Section::make('Hero')
                    ->description('Shown above the fold on the About and Contact layouts.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('data.eyebrow')->label('Eyebrow')->maxLength(120),
                        TextInput::make('data.hq_note')->label('Head-office note (Contact)')->maxLength(200),
                        Textarea::make('data.intro')->label('Intro paragraph')->rows(3)->columnSpanFull(),
                    ]),

                Section::make('Pillars')
                    ->description('The four icon cards under the hero.')
                    ->schema([
                        Repeater::make('data.pillars')
                            ->hiddenLabel()
                            ->columns(3)
                            ->maxItems(4)
                            ->defaultItems(0)
                            ->schema([
                                Select::make('icon')->options(self::ICONS)->default('shield-check')->required(),
                                TextInput::make('title')->required()->maxLength(60),
                                TextInput::make('text')->label('Description')->required()->maxLength(180),
                            ]),
                    ]),

                Section::make('Mission, vision & “Why Cameroon”')
                    ->description('About layout only.')
                    ->columns(2)
                    ->visible(fn ($get) => $get('template') === 'about')
                    ->schema([
                        Textarea::make('data.mission')->label('Mission')->rows(4),
                        Textarea::make('data.vision')->label('Vision')->rows(4),
                        TextInput::make('data.why_title')->label('“Why Cameroon” heading')->maxLength(120),
                        TextInput::make('data.story_title')->label('Story heading')->maxLength(160),
                        Textarea::make('data.why_intro')->label('“Why Cameroon” intro')->rows(3)->columnSpanFull(),
                        TagsInput::make('data.why_points')->label('“Why Cameroon” bullet points')->columnSpanFull(),
                    ]),

                Section::make('Page body')
                    ->description('Long-form prose rendered as the editable content section of the page.')
                    ->schema([
                        Repeater::make('data.blocks')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => str(($state['content'] ?? ''))->limit(60)->value() ?: ucfirst($state['type'] ?? 'block'))
                            ->schema([
                                Select::make('type')
                                    ->options([
                                        'paragraph' => 'Paragraph',
                                        'heading' => 'Heading',
                                        'subheading' => 'Subheading',
                                        'list' => 'Bullet list',
                                    ])
                                    ->default('paragraph')
                                    ->live()
                                    ->required(),
                                Textarea::make('content')
                                    ->rows(4)
                                    ->visible(fn ($get) => $get('type') !== 'list'),
                                TagsInput::make('items')
                                    ->label('List items')
                                    ->visible(fn ($get) => $get('type') === 'list'),
                            ]),
                    ]),

                Section::make('Call to action')
                    ->columns(1)
                    ->schema([
                        TextInput::make('data.cta_title')->label('CTA heading')->maxLength(160),
                        Textarea::make('data.cta_text')->label('CTA text')->rows(2),
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
