<?php

namespace App\Filament\Exporter\Resources\Products\Schemas;

use App\Enums\PriceUnit;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ProductForm
{
    /**
     * Raw/dimensional timber forms — round or sawn stock traded by physical
     * dimension, where thickness/width/length/grade/moisture are meaningful.
     *
     * @return list<string>
     */
    protected static function dimensionalTypes(): array
    {
        return [
            ProductType::SawnTimber->value,
            ProductType::Logs->value,
            ProductType::Beams->value,
            ProductType::Planks->value,
            ProductType::Boules->value,
            ProductType::Squares->value,
            ProductType::Sleepers->value,
            ProductType::Poles->value,
            ProductType::Slabs->value,
        ];
    }

    /**
     * Finished/manufactured goods — still meaningfully dimensioned, but sold
     * as a processed product rather than raw stock.
     *
     * @return list<string>
     */
    protected static function finishedTypes(): array
    {
        return [
            ProductType::Veneer->value,
            ProductType::Flooring->value,
            ProductType::Decking->value,
            ProductType::Mouldings->value,
            ProductType::Plywood->value,
            ProductType::LaminatedPanels->value,
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('messages.filament.product.section_core'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label(__('messages.filament.product.name'))->required()->maxLength(200),
                        Select::make('product_type')
                            ->label(__('messages.filament.product.product_type'))
                            ->options(ProductType::options())
                            ->native(false)
                            ->live()
                            ->helperText(fn ($state): ?string => $state ? ProductType::tryFrom($state)?->description() : __('messages.filament.product.product_type_help_default'))
                            ->required(),
                        Select::make('species_id')
                            ->relationship('species', 'common_name')
                            ->searchable()->preload()
                            ->label(__('messages.filament.product.species'))
                            ->required(fn (Get $get): bool => $get('product_type') !== ProductType::Charcoal->value)
                            ->visible(fn (Get $get): bool => $get('product_type') !== ProductType::Charcoal->value),
                        TextInput::make('grade')
                            ->label(__('messages.filament.product.grade'))
                            ->maxLength(120)
                            ->visible(fn (Get $get): bool => $get('product_type') !== ProductType::Charcoal->value),
                        TextInput::make('tagline')
                            ->label(__('messages.filament.product.tagline'))
                            ->maxLength(160)
                            ->columnSpanFull()
                            ->helperText(__('messages.filament.product.tagline_help')),
                        Textarea::make('description')->label(__('messages.filament.product.description'))->rows(5)->columnSpanFull(),
                    ]),

                Section::make(__('messages.filament.product.section_pricing'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('price_amount')->numeric()->label(__('messages.filament.product.price')),
                        TextInput::make('price_currency')->label(__('messages.filament.product.price_currency'))->maxLength(3)->default('XAF'),
                        Select::make('price_unit')->label(__('messages.filament.product.price_unit'))->options(PriceUnit::options())->default('m3'),
                        TextInput::make('moq_quantity')->numeric()->label(__('messages.filament.product.minimum_order')),
                        Select::make('moq_unit')->label(__('messages.filament.product.moq_unit'))->options(PriceUnit::options())->default('m3'),
                    ]),

                Section::make(__('messages.filament.product.section_dimensions'))
                    ->columns(3)
                    ->visible(fn (Get $get): bool => in_array($get('product_type'), [
                        ...self::dimensionalTypes(),
                        ...self::finishedTypes(),
                    ], true))
                    ->schema([
                        TextInput::make('thickness_mm')->numeric()->label(__('messages.filament.product.thickness_mm')),
                        TextInput::make('width_min_mm')->numeric()->label(__('messages.filament.product.width_min_mm')),
                        TextInput::make('width_max_mm')->numeric()->label(__('messages.filament.product.width_max_mm')),
                        TextInput::make('length_min_m')->numeric()->label(__('messages.filament.product.length_min_m')),
                        TextInput::make('length_max_m')->numeric()->label(__('messages.filament.product.length_max_m')),
                        TextInput::make('moisture_content')
                            ->label(__('messages.filament.product.moisture_content'))
                            ->maxLength(60)
                            ->visible(fn (Get $get): bool => in_array($get('product_type'), self::dimensionalTypes(), true)),
                        TextInput::make('origin')->label(__('messages.filament.product.origin'))->maxLength(120)->default('Cameroon'),
                        TextInput::make('certification')->label(__('messages.filament.product.certification'))->maxLength(150),
                    ]),

                Section::make(__('messages.filament.product.section_charcoal'))
                    ->columns(2)
                    ->visible(fn (Get $get): bool => $get('product_type') === ProductType::Charcoal->value)
                    ->schema([
                        TextInput::make('origin')->label(__('messages.filament.product.origin'))->maxLength(120)->default('Cameroon'),
                        TextInput::make('certification')->label(__('messages.filament.product.certification'))->maxLength(150),
                    ]),

                Section::make(__('messages.filament.product.section_content'))
                    ->schema([
                        KeyValue::make('specifications')
                            ->keyLabel(__('messages.filament.product.specifications_key'))->valueLabel(__('messages.filament.product.specifications_value'))
                            ->helperText(__('messages.filament.product.specifications_help'))
                            ->columnSpanFull(),
                        TagsInput::make('key_benefits')->placeholder(__('messages.filament.product.key_benefits_placeholder'))->columnSpanFull(),
                        TextInput::make('materials_used')
                            ->label(__('messages.filament.product.materials_used'))
                            ->maxLength(255)
                            ->helperText(__('messages.filament.product.materials_used_help'))
                            ->visible(fn (Get $get): bool => in_array($get('product_type'), self::finishedTypes(), true)),
                        TextInput::make('finish')
                            ->label(__('messages.filament.product.finish'))
                            ->maxLength(120)
                            ->helperText(__('messages.filament.product.finish_help'))
                            ->visible(fn (Get $get): bool => in_array($get('product_type'), self::finishedTypes(), true)),
                        TextInput::make('dimensions_description')
                            ->label(__('messages.filament.product.dimensions_description'))
                            ->maxLength(255)
                            ->helperText(__('messages.filament.product.dimensions_description_help'))
                            ->visible(fn (Get $get): bool => in_array($get('product_type'), self::finishedTypes(), true)),
                        KeyValue::make('custom_attributes')
                            ->label(__('messages.filament.product.custom_attributes'))
                            ->keyLabel(__('messages.filament.product.custom_attributes_key'))->valueLabel(__('messages.filament.product.custom_attributes_value'))
                            ->helperText(__('messages.filament.product.custom_attributes_help'))
                            ->visible(fn (Get $get): bool => in_array($get('product_type'), self::finishedTypes(), true))
                            ->columnSpanFull(),
                    ]),

                Section::make(__('messages.filament.product.section_media'))
                    ->description(__('messages.filament.product.media_desc'))
                    ->schema([
                        FileUpload::make('primary_image_path')
                            ->label(__('messages.filament.product.primary_image'))
                            ->image()->imageEditor()
                            ->disk('public')->directory('products')
                            ->columnSpanFull(),
                    ]),

                Section::make(__('messages.filament.product.section_publication'))
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->label(__('messages.filament.product.status'))
                            ->options(ProductStatus::options())
                            ->default(ProductStatus::Draft->value)
                            ->required()
                            ->helperText(__('messages.filament.product.status_help')),
                    ]),
            ]);
    }
}
