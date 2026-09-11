<?php

namespace App\Filament\Resources\Coupons\Schemas;

use App\Models\Plan;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Coupon')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->required()
                            ->maxLength(40)
                            ->unique(ignoreRecord: true)
                            ->helperText('e.g. LAUNCH20. Stored uppercase.')
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state === null ? null : strtoupper(trim($state))),

                        Select::make('type')
                            ->options(['percent' => 'Percentage', 'fixed' => 'Fixed amount'])
                            ->required()
                            ->live()
                            ->default('percent'),

                        TextInput::make('value')
                            ->label(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('type') === 'fixed' ? 'Amount' : 'Percentage')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->suffix(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('type') === 'percent' ? '%' : null)
                            ->formatStateUsing(function (\Filament\Schemas\Components\Utilities\Get $get, $state) {
                                if ($state === null) {
                                    return null;
                                }

                                return $get('type') === 'percent' ? (string) round(((float) $state) * 100, 4) : (string) $state;
                            })
                            ->dehydrateStateUsing(function (\Filament\Schemas\Components\Utilities\Get $get, $state) {
                                return $get('type') === 'percent' ? bcdiv((string) $state, '100', 4) : bcadd((string) $state, '0', 4);
                            })
                            ->helperText('Percentage coupons are stored as a fraction (20% → 0.20).'),

                        Select::make('currency')
                            ->options(['XAF' => 'XAF', 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'CNY' => 'CNY'])
                            ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('type') === 'fixed')
                            ->required(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('type') === 'fixed')
                            ->helperText('Required for a fixed-amount coupon; ignored for percentage coupons.'),

                        Select::make('applies_to_segments')
                            ->label('Applies to segments')
                            ->multiple()
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
                            ->helperText('Leave blank to apply to every segment.'),

                        Select::make('applies_to_plan_ids')
                            ->label('Applies to plans')
                            ->multiple()
                            ->options(fn () => Plan::query()->orderBy('name')->pluck('name', 'id'))
                            ->placeholder('All plans within the segment filter')
                            ->dehydrateStateUsing(fn ($state) => is_array($state) && $state !== [] ? array_map('intval', $state) : null),

                        TextInput::make('max_redemptions')
                            ->numeric()
                            ->minValue(1)
                            ->helperText('Blank = unlimited total redemptions.'),

                        TextInput::make('max_redemptions_per_company')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->helperText('Blank = unlimited per company.'),

                        Toggle::make('stacks_with_annual')
                            ->helperText('Off by default (plan §19) — whether this coupon also applies alongside the annual-billing discount.'),

                        Toggle::make('is_active')->default(true),

                        DateTimePicker::make('valid_from')->helperText('Blank = valid immediately.'),
                        DateTimePicker::make('valid_until')->helperText('Blank = no expiry.'),

                        Textarea::make('notes')->rows(3)->columnSpanFull(),
                    ]),
            ]);
    }
}
