<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Enums\ProductType;
use App\Models\Species;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Article')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->required()->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, callable $get, callable $set): void {
                                if (blank($get('slug')) && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            })
                            ->columnSpanFull(),
                        TextInput::make('slug')
                            ->maxLength(200)
                            ->unique(ignoreRecord: true)
                            ->helperText('Leave blank to generate from the title. Changing it breaks existing links.'),
                        Select::make('category')
                            ->options(ArticleCategory::options())
                            ->required()
                            ->native(false),
                        TextInput::make('h1')
                            ->label('On-page headline (H1)')
                            ->maxLength(255)
                            ->helperText('Optional. Falls back to the title.')
                            ->columnSpanFull(),
                        Textarea::make('excerpt')
                            ->rows(3)
                            ->maxLength(600)
                            ->helperText('One or two sentences. Used on cards and as the meta-description fallback.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Publication')
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->options(ArticleStatus::options())
                            ->default(ArticleStatus::Draft->value)
                            ->required()
                            ->live()
                            ->native(false)
                            ->helperText('Only Published articles are visible on /insights, in the sitemap or in llms.txt.'),
                        DateTimePicker::make('published_at')
                            ->label('Publish at')
                            ->seconds(false)
                            ->native(false)
                            ->required(fn (callable $get): bool => $get('status') === ArticleStatus::Published->value)
                            ->helperText('A future date keeps the article hidden until then.'),
                        TextInput::make('reading_minutes')
                            ->label('Reading time (minutes)')
                            ->numeric()->minValue(1)->maxValue(120)
                            ->helperText('Leave blank to compute from the body.'),
                    ]),

                Section::make('Body')
                    ->description('Markdown. Do not add an H1 — the page renders one. Structure with H2s; the table of contents is built from them. Internal links: [Sapele](species:sapele), [sawn timber](marketplace:sawn_timber), [post an RFQ](rfq:).')
                    ->schema([
                        MarkdownEditor::make('body')
                            ->hiddenLabel()
                            // The house standard for regulatory content, enforced at
                            // the point of authoring rather than only for the
                            // markdown files in content/articles/.
                            ->rule(static fn (callable $get): Closure => static function (string $attribute, $value, Closure $fail) use ($get): void {
                                if (! self::isShippingRegulation($get)) {
                                    return;
                                }

                                if (! Str::contains((string) $value, 'not legal advice', ignoreCase: true)) {
                                    $fail('A published regulation article must carry a "not legal advice" disclaimer in its body.');

                                    return;
                                }

                                if (! Str::startsWith(trim((string) $value), '>')) {
                                    $fail('The "not legal advice" disclaimer must be the first element of the body (a blockquote).');
                                }
                            })
                            ->columnSpanFull(),
                    ]),

                Section::make('Hero image')
                    ->schema([
                        FileUpload::make('hero_image_path')
                            ->hiddenLabel()
                            ->image()->imageEditor()
                            ->disk('public')->directory('articles')
                            ->columnSpanFull(),
                    ]),

                Section::make('SEO')
                    ->columns(2)
                    ->schema([
                        TextInput::make('meta_title')->maxLength(255),
                        TagsInput::make('keywords')->placeholder('Add a keyword'),
                        Textarea::make('meta_description')->rows(2)->maxLength(320)->columnSpanFull(),
                    ]),

                Section::make('FAQs')
                    ->description('Rendered on the page AND emitted as FAQPage structured data. Answer engines discount markup with no visible on-page match, so anything added here is always shown.')
                    ->schema([
                        Repeater::make('faqs')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['question'] ?? null)
                            ->schema([
                                TextInput::make('question')->required()->maxLength(255),
                                Textarea::make('answer')->required()->rows(3),
                            ]),
                    ]),

                Section::make('Sources')
                    ->description('Citations shown at the foot of the article.')
                    ->schema([
                        Repeater::make('sources')
                            ->hiddenLabel()
                            ->defaultItems(0)
                            ->reorderable()
                            ->columns(2)
                            // Same standard: a published regulation article must cite,
                            // and every citation must record when it was accessed.
                            ->rule(static fn (callable $get): Closure => static function (string $attribute, $value, Closure $fail) use ($get): void {
                                if (! self::isShippingRegulation($get)) {
                                    return;
                                }

                                $rows = array_values((array) $value);

                                if ($rows === []) {
                                    $fail('A published regulation article must cite at least one source.');

                                    return;
                                }

                                foreach ($rows as $row) {
                                    $label = is_array($row) ? (string) ($row['label'] ?? '') : '';

                                    if (! Str::contains($label, 'accessed', ignoreCase: true)) {
                                        $fail('Every source on a regulation article must record an access date — include "accessed <date>" in the label.');

                                        return;
                                    }
                                }
                            })
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? ($state['url'] ?? null))
                            ->schema([
                                TextInput::make('url')->url()->required()->maxLength(512),
                                TextInput::make('label')->required()->maxLength(180),
                            ]),
                    ]),

                Section::make('Catalogue links')
                    ->columns(2)
                    ->schema([
                        Select::make('related_species_ids')
                            ->label('Related species')
                            ->multiple()
                            // Unpublished species stay selectable — pre-publish
                            // wiring is legitimate — but they are labelled, because
                            // Article::relatedSpecies() filters to published() at
                            // render time and would otherwise silently drop them.
                            ->options(fn (): array => Species::query()
                                ->orderBy('common_name')
                                ->get(['id', 'common_name', 'is_published'])
                                ->mapWithKeys(fn (Species $s): array => [
                                    $s->getKey() => $s->common_name.($s->is_published ? '' : ' (draft)'),
                                ])->all())
                            ->searchable()
                            ->helperText('Rendered as the "Species covered here" rail. Items marked (draft) are not published, so they will not appear on the public page until they are.'),
                        Select::make('related_product_types')
                            ->label('Related product types')
                            ->multiple()
                            ->options(ProductType::options())
                            ->searchable(),
                    ]),

                Section::make('Authorship')
                    ->description('E-E-A-T: leave both fields blank unless a real, named person wrote this. The byline then reads "Cameroon Timber Hub editorial" and the structured data attributes the piece to the organisation. Never invent an author or credentials.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('author_name')->label('Author name')->maxLength(150),
                        TextInput::make('author_role')->label('Author role / credentials')->maxLength(150),
                    ]),
            ]);
    }

    /**
     * True when the record being saved is a regulation article that is (or is
     * about to be) publicly visible. Drafts are left alone so a piece can be
     * written up over several saves.
     */
    private static function isShippingRegulation(callable $get): bool
    {
        return $get('category') === ArticleCategory::Regulation->value
            && $get('status') === ArticleStatus::Published->value;
    }
}
