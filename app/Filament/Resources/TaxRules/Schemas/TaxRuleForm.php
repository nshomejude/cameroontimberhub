<?php

namespace App\Filament\Resources\TaxRules\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TaxRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tax rule')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(120)
                            ->helperText('e.g. "Cameroon TVA".'),
                        TextInput::make('jurisdiction')->required()->maxLength(8)
                            ->helperText('ISO 3166-1 alpha-2 country code (e.g. CM), or * for the rest-of-world default.')
                            ->dehydrateStateUsing(fn (string $state): string => strtoupper(trim($state))),

                        // Stored as a fraction (0.1925); entered/shown as a percentage (19.25).
                        TextInput::make('rate')
                            ->label('Rate')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->formatStateUsing(fn ($state) => $state === null ? null : (string) round(((float) $state) * 100, 4))
                            ->dehydrateStateUsing(fn ($state) => bcdiv((string) $state, '100', 4))
                            ->helperText('Percentage, e.g. 19.25. Cannot be edited on an active rule — create a new rule with a later effective-from.'),

                        Select::make('applies_to')
                            ->label('Applies to segment')
                            ->options([
                                'sell' => 'Sell timber locally',
                                'buy' => 'Buy timber',
                                'deal' => 'Deal timber',
                                'export' => 'Export timber',
                                'buy-international' => 'Buy internationally',
                                'verify-comply' => 'Verify & comply',
                                'analyze' => 'Analyze the market',
                                'learn' => 'Learn',
                            ])
                            ->placeholder('All segments')
                            ->helperText('Leave blank to apply to every plan segment in this jurisdiction.'),

                        Toggle::make('is_active')->default(false)
                            ->helperText('Off until the tax authority registration is confirmed (plan §7.3).'),
                        DatePicker::make('effective_from')->helperText('Blank = applies from the beginning of time.'),
                        DatePicker::make('effective_until')->helperText('Blank = open-ended.'),
                        Textarea::make('notes')->rows(3)->columnSpanFull(),
                    ]),
            ]);
    }
}
