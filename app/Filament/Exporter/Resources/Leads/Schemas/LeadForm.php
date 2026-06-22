<?php

namespace App\Filament\Exporter\Resources\Leads\Schemas;

use App\Enums\LeadStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Buyer')
                    ->columns(3)
                    ->schema([
                        TextInput::make('buyer_name')->disabled(),
                        TextInput::make('buyer_email')->disabled(),
                        TextInput::make('buyer_country_code')->label('Country')->disabled(),
                    ]),

                Section::make('Pipeline')
                    ->columns(2)
                    ->schema([
                        Select::make('status')
                            ->options(collect(LeadStatus::cases())->mapWithKeys(fn (LeadStatus $s) => [$s->value => $s->label()])->all())
                            ->required(),
                        TextInput::make('value_amount')->label('Estimated value')->numeric(),
                        Textarea::make('notes')->label('Private notes')->rows(4)->columnSpanFull()
                            ->helperText('Visible only to your company.'),
                    ]),
            ]);
    }
}
