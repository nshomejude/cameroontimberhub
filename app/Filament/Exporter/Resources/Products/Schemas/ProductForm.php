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
                Section::make('Core')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(200),
                        Select::make('product_type')
                            ->options(ProductType::options())
                            ->native(false)
                            ->live()
                            ->helperText(fn ($state): ?string => $state ? ProductType::tryFrom($state)?->description() : 'The processing form this listing is traded in.')
                            ->required(),
                        Select::make('species_id')
                            ->relationship('species', 'common_name')
                            ->searchable()->preload()
                            ->label('Species')
                            ->required(fn (Get $get): bool => $get('product_type') !== ProductType::Charcoal->value)
                            ->visible(fn (Get $get): bool => $get('product_type') !== ProductType::Charcoal->value),
                        TextInput::make('grade')
                            ->maxLength(120)
                            ->visible(fn (Get $get): bool => $get('product_type') !== ProductType::Charcoal->value),
                        TextInput::make('tagline')
                            ->maxLength(160)
                            ->columnSpanFull()
                            ->helperText('Short strapline under the title on mobile, e.g. "Premium African Hardwood – Export Quality". Leave blank to omit the line.'),
                        Textarea::make('description')->rows(5)->columnSpanFull(),
                    ]),

                Section::make('Pricing & quantity')
                    ->columns(3)
                    ->schema([
                        TextInput::make('price_amount')->numeric()->label('Price'),
                        TextInput::make('price_currency')->maxLength(3)->default('XAF'),
                        Select::make('price_unit')->options(PriceUnit::options())->default('m3'),
                        TextInput::make('moq_quantity')->numeric()->label('Minimum order'),
                        Select::make('moq_unit')->options(PriceUnit::options())->default('m3'),
                    ]),

                Section::make('Dimensions & properties')
                    ->columns(3)
                    ->visible(fn (Get $get): bool => in_array($get('product_type'), [
                        ...self::dimensionalTypes(),
                        ...self::finishedTypes(),
                    ], true))
                    ->schema([
                        TextInput::make('thickness_mm')->numeric()->label('Thickness (mm)'),
                        TextInput::make('width_min_mm')->numeric()->label('Min width (mm)'),
                        TextInput::make('width_max_mm')->numeric()->label('Max width (mm)'),
                        TextInput::make('length_min_m')->numeric()->label('Min length (m)'),
                        TextInput::make('length_max_m')->numeric()->label('Max length (m)'),
                        TextInput::make('moisture_content')
                            ->maxLength(60)
                            ->visible(fn (Get $get): bool => in_array($get('product_type'), self::dimensionalTypes(), true)),
                        TextInput::make('origin')->maxLength(120)->default('Cameroon'),
                        TextInput::make('certification')->maxLength(150),
                    ]),

                Section::make('Charcoal & biomass details')
                    ->columns(2)
                    ->visible(fn (Get $get): bool => $get('product_type') === ProductType::Charcoal->value)
                    ->schema([
                        TextInput::make('origin')->maxLength(120)->default('Cameroon'),
                        TextInput::make('certification')->maxLength(150),
                    ]),

                Section::make('Content')
                    ->schema([
                        KeyValue::make('specifications')
                            ->keyLabel('Property')->valueLabel('Value')
                            ->helperText('e.g. botanical_name, density, packaging, delivery.')
                            ->columnSpanFull(),
                        TagsInput::make('key_benefits')->placeholder('Add a benefit')->columnSpanFull(),
                    ]),

                Section::make('Media')
                    ->description('The first image is the primary listing photo.')
                    ->schema([
                        FileUpload::make('primary_image_path')
                            ->image()->imageEditor()
                            ->disk('public')->directory('products')
                            ->columnSpanFull(),
                    ]),

                Section::make('Publication')
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->options(ProductStatus::options())
                            ->default(ProductStatus::Draft->value)
                            ->required()
                            ->helperText('Save as Draft to preview before this listing becomes publicly visible.'),
                    ]),
            ]);
    }
}
