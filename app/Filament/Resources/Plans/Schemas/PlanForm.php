<?php

namespace App\Filament\Resources\Plans\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Plan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(120),
                        TextInput::make('slug')->maxLength(60)->unique(ignoreRecord: true)
                            ->helperText('Leave blank to keep; used by feature gates.'),
                        Textarea::make('description')->rows(2)->columnSpanFull(),
                        TextInput::make('price_amount')->numeric()->default(0)->required(),
                        Select::make('price_currency')->options(['XAF' => 'XAF', 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'CNY' => 'CNY'])->default('XAF')->required(),
                        Select::make('billing_period')->options(['monthly' => 'Monthly', 'yearly' => 'Yearly', 'once' => 'One-off'])->default('yearly')->required(),
                        TextInput::make('sort_order')->numeric()->default(0),
                        Toggle::make('is_active')->default(true),
                    ]),

                Section::make('Features (feature gates)')
                    ->columns(2)
                    ->schema([
                        Toggle::make('features.verified_badge')->label('Verified badge'),
                        Toggle::make('features.leads_receive')->label('Receive RFQ leads'),
                        Toggle::make('features.featured')->label('Featured placement'),
                        Toggle::make('features.api')->label('API access'),
                        TextInput::make('features.max_gallery')->label('Max gallery images')->numeric()->default(3),
                    ]),
            ]);
    }
}
