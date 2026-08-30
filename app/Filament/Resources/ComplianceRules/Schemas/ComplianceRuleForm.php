<?php

namespace App\Filament\Resources\ComplianceRules\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ComplianceRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Compliance rule')
                    ->columns(2)
                    ->schema([
                        // Blueprint §16: "No compliance requirement should exist in the
                        // production rules engine without a source." Required both here
                        // and at the DB level via the regulatory_source_id FK.
                        Select::make('regulatory_source_id')
                            ->relationship('regulatorySource', 'instrument_name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->label('Regulatory source'),
                        TextInput::make('regulatory_framework')->required()->maxLength(120),
                        TextInput::make('market')->maxLength(120),
                        TextInput::make('country_code')->maxLength(2)->label('Country code (ISO 2)'),
                        TextInput::make('product_category')->maxLength(120),
                        TextInput::make('commodity_code')->maxLength(60),
                        TextInput::make('supplier_type')->maxLength(60),
                        TextInput::make('transaction_type')->maxLength(60),
                        DatePicker::make('effective_date'),
                        DatePicker::make('review_date'),
                        Toggle::make('is_active')->default(true),
                        Textarea::make('decision_rules')->rows(3)->columnSpanFull()
                            ->helperText('Plain-language description of the decision logic.'),
                    ]),
            ]);
    }
}
