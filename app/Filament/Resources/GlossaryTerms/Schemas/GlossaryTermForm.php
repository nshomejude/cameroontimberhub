<?php

namespace App\Filament\Resources\GlossaryTerms\Schemas;

use App\Models\GlossaryTerm;
use App\Models\Species;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class GlossaryTermForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Term')
                    ->columns(2)
                    ->schema([
                        TextInput::make('term')
                            ->required()->maxLength(150)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, callable $get, callable $set): void {
                                if (blank($get('slug')) && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),
                        TextInput::make('slug')
                            ->maxLength(180)
                            ->unique(ignoreRecord: true)
                            ->helperText('Leave blank to generate from the term. Changing it breaks existing links.'),
                        TextInput::make('french_term')
                            ->label('French term')
                            ->maxLength(150)
                            ->helperText('Optional. Only fill in when the French trade term is genuinely known.'),
                        Toggle::make('is_published')
                            ->label('Published')
                            ->default(true)
                            ->helperText('Unpublished terms are hidden from the glossary, search, the sitemap and llms.txt.'),
                    ]),

                Section::make('Definition')
                    ->description('One or two plain sentences that answer "what is this?". This is what an answer engine quotes, and it is emitted as the DefinedTerm description. Never guess — a shorter, correct definition beats a padded one.')
                    ->schema([
                        Textarea::make('definition')
                            ->hiddenLabel()
                            ->required()
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('explanation')
                            ->label('Longer explanation')
                            ->rows(5)
                            ->helperText('Optional. Context, how it is used in the Cameroon trade, common pitfalls.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Cross-links')
                    ->columns(2)
                    ->schema([
                        Select::make('related_term_ids')
                            ->label('Related terms')
                            ->multiple()
                            ->options(fn (?GlossaryTerm $record): array => GlossaryTerm::query()
                                ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                                ->orderBy('term')->pluck('term', 'id')->all())
                            ->searchable(),
                        Select::make('related_species_ids')
                            ->label('Related species')
                            ->multiple()
                            ->options(fn (): array => Species::query()->orderBy('common_name')->pluck('common_name', 'id')->all())
                            ->searchable(),
                    ]),

                // The sort_order column exists but nothing reads it — the public
                // glossary lists terms alphabetically — so it is deliberately
                // not exposed as a form control.
                Section::make('SEO')
                    ->schema([
                        Textarea::make('meta_description')
                            ->rows(2)->maxLength(320)
                            ->helperText('Leave blank to fall back to the definition.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
