<?php

namespace App\Filament\Resources\CommissionRules\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CommissionRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Commission rule')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(120)
                            ->helperText('e.g. "Starter / Professional tier".'),

                        Select::make('segment')
                            ->label('Segment')
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
                            ->helperText('Leave blank to apply to every plan segment.'),

                        TextInput::make('plan_tier')
                            ->label('Plan tier (slug)')
                            ->maxLength(60)
                            ->helperText('The supplier plan slug this rule targets (e.g. "free", "professional", "enterprise"). Leave blank to apply to every tier in the segment.'),

                        // Stored as a fraction (0.025); entered/shown as a percentage (2.5).
                        TextInput::make('domestic_rate')
                            ->label('Domestic rate')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->formatStateUsing(fn ($state) => $state === null ? null : (string) round(((float) $state) * 100, 4))
                            ->dehydrateStateUsing(fn ($state) => bcdiv((string) $state, '100', 4)),

                        TextInput::make('international_rate')
                            ->label('International rate')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->formatStateUsing(fn ($state) => $state === null ? null : (string) round(((float) $state) * 100, 4))
                            ->dehydrateStateUsing(fn ($state) => bcdiv((string) $state, '100', 4)),

                        TextInput::make('cap_amount')
                            ->label('Cap amount')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Maximum commission in the order currency\'s major unit. Blank = no fixed-amount cap.'),

                        TextInput::make('cap_percent')
                            ->label('Cap percent')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->formatStateUsing(fn ($state) => $state === null ? null : (string) round(((float) $state) * 100, 4))
                            ->dehydrateStateUsing(fn ($state) => $state === null || $state === '' ? null : bcdiv((string) $state, '100', 4))
                            ->helperText('An additional cap expressed as a percentage of the subtotal. Blank = no percent cap.'),

                        Toggle::make('is_active')->default(false)
                            ->helperText('Off until reviewed. Editing rate/cap on an active rule is blocked — supersede with a new effective_from row.'),
                        DatePicker::make('effective_from')->helperText('Blank = applies from the beginning of time.'),
                        DatePicker::make('effective_until')->helperText('Blank = open-ended.'),
                        Textarea::make('notes')->rows(3)->columnSpanFull(),
                    ]),
            ]);
    }
}
