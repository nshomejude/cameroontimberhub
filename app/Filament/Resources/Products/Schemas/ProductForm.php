<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\PriceUnit;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Core')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(200),
                        TextInput::make('slug')
                            ->maxLength(200)
                            ->unique(ignoreRecord: true)
                            ->helperText('Leave blank to auto-generate from the name.'),
                        Select::make('company_id')
                            ->relationship('company', 'legal_name')
                            ->searchable()->preload()->required()
                            ->label('Supplier'),
                        Select::make('species_id')
                            ->relationship('species', 'common_name')
                            ->searchable()->preload()
                            ->label('Species'),
                        Select::make('product_type')
                            ->options(ProductType::options())
                            ->required(),
                        TextInput::make('grade')->maxLength(120),
                        Textarea::make('description')->rows(5)->columnSpanFull(),
                    ]),

                Section::make('Pricing')
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
                    ->schema([
                        TextInput::make('thickness_mm')->numeric()->label('Thickness (mm)'),
                        TextInput::make('width_min_mm')->numeric()->label('Min width (mm)'),
                        TextInput::make('width_max_mm')->numeric()->label('Max width (mm)'),
                        TextInput::make('length_min_m')->numeric()->label('Min length (m)'),
                        TextInput::make('length_max_m')->numeric()->label('Max length (m)'),
                        TextInput::make('moisture_content')->maxLength(60),
                        TextInput::make('origin')->maxLength(120)->default('Cameroon'),
                        TextInput::make('certification')->maxLength(150),
                    ]),

                Section::make('Content')
                    ->schema([
                        KeyValue::make('specifications')
                            ->keyLabel('Property')->valueLabel('Value')
                            ->helperText('e.g. botanical_name, density, durability_class, packaging, delivery.')
                            ->columnSpanFull(),
                        TagsInput::make('key_benefits')->placeholder('Add a benefit')->columnSpanFull(),
                    ]),

                Section::make('Media')
                    ->schema([
                        FileUpload::make('primary_image_path')
                            ->image()->imageEditor()
                            ->disk('public')->directory('products')
                            ->columnSpanFull(),
                    ]),

                Section::make('Visibility & social proof')
                    ->columns(3)
                    ->schema([
                        Select::make('status')->options(ProductStatus::options())->default('draft')->required(),
                        Toggle::make('is_featured'),
                        Toggle::make('is_best_seller'),
                        TextInput::make('rating')->numeric()->minValue(0)->maxValue(5)->step(0.1),
                        TextInput::make('reviews_count')->numeric()->default(0),
                        TextInput::make('buyers_count')->numeric()->default(0),
                    ]),

                Section::make('SEO')
                    ->columns(2)
                    ->schema([
                        TextInput::make('meta_title')->maxLength(255),
                        Textarea::make('meta_description')->rows(2)->maxLength(320),
                    ]),
            ]);
    }
}
