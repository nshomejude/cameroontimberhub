<?php

namespace App\Filament\Exporter\Resources\Quotes\Schemas;

use App\Enums\QuoteStatus;
use App\Enums\RfqCurrency;
use App\Enums\RfqIncoterm;
use App\Enums\RfqStatus;
use App\Enums\RfqUnit;
use App\Enums\TimberForm;
use App\Models\Rfq;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QuoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request')
                    ->description('You can only quote requests that were routed to your company and approved by our team.')
                    ->columns(2)
                    ->schema([
                        Select::make('rfq_id')
                            ->label('Request for quotation')
                            ->options(fn (): array => self::quotableRfqs())
                            ->required()
                            ->searchable()
                            ->disabledOn('edit'),

                        Select::make('currency')
                            ->options(collect(RfqCurrency::cases())->mapWithKeys(fn (RfqCurrency $c) => [$c->value => $c->label()])->all())
                            ->default(RfqCurrency::USD->value)
                            ->required(),
                    ]),

                Section::make('Line items')
                    ->description('Line totals, the subtotal and the total are computed by the platform from quantity x unit price.')
                    ->schema([
                        Repeater::make('items')
                            ->relationship()
                            ->label('')
                            ->minItems(1)
                            ->defaultItems(1)
                            ->columns(4)
                            ->itemLabel(fn (array $state): ?string => $state['description'] ?? null)
                            ->schema([
                                TextInput::make('description')
                                    ->required()->maxLength(255)->columnSpan(2),

                                Select::make('species_id')
                                    ->label('Species')
                                    ->relationship('species', 'common_name')
                                    ->searchable()->preload()->columnSpan(2),

                                Select::make('form')
                                    ->options(collect(TimberForm::cases())->mapWithKeys(fn (TimberForm $f) => [$f->value => $f->label()])->all()),

                                TextInput::make('grade')->maxLength(60),
                                TextInput::make('dimensions')->maxLength(255)->columnSpan(2),

                                TextInput::make('quantity')
                                    ->numeric()->required()->minValue(0.01)->step('0.01'),

                                Select::make('unit')
                                    ->options(RfqUnit::options())
                                    ->default(RfqUnit::CubicMetre->value)
                                    ->required(),

                                TextInput::make('unit_price')
                                    ->numeric()->required()->minValue(0)->step('0.01')
                                    ->columnSpan(2),

                                Textarea::make('notes')->rows(2)->columnSpanFull(),
                            ]),
                    ]),

                Section::make('Charges')
                    ->columns(2)
                    ->schema([
                        TextInput::make('shipping_amount')->numeric()->minValue(0)->step('0.01')
                            ->helperText('Optional. Added to the subtotal.'),
                        TextInput::make('tax_amount')->numeric()->minValue(0)->step('0.01')
                            ->helperText('Optional. Added to the subtotal.'),
                    ]),

                Section::make('Terms')
                    ->columns(2)
                    ->schema([
                        Select::make('incoterm')
                            ->options(collect(RfqIncoterm::cases())->mapWithKeys(fn (RfqIncoterm $i) => [$i->value => $i->label()])->all()),

                        TextInput::make('lead_time_days')->label('Lead time (days)')->numeric()->minValue(0),

                        TextInput::make('validity_days')->label('Validity (days)')->numeric()->minValue(1),

                        DatePicker::make('valid_until')
                            ->helperText('After this date the quote lapses and the buyer can no longer accept it.'),

                        TextInput::make('payment_terms')->maxLength(255)
                            ->placeholder('30% advance, 70% on delivery')->columnSpanFull(),

                        Textarea::make('notes')->label('Notes to the buyer')->rows(4)->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * RFQs this supplier may quote: approved, routed to one of their companies,
     * and without an existing active quote from them.
     *
     * @return array<int, string>
     */
    private static function quotableRfqs(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        $companyIds = $user->companies()->pluck('companies.id');

        return Rfq::query()
            ->whereNotNull('email_verified_at')
            ->where('status', RfqStatus::Approved->value)
            ->whereHas('routings', fn ($q) => $q->whereIn('company_id', $companyIds))
            ->whereDoesntHave('quotes', fn ($q) => $q->whereIn('company_id', $companyIds)
                ->where('status', '!=', QuoteStatus::Withdrawn->value))
            ->orderByDesc('created_at')
            ->get()
            ->mapWithKeys(fn (Rfq $rfq) => [
                $rfq->getKey() => $rfq->reference_code.' — '.($rfq->title ?: 'Quote request'),
            ])
            ->all();
    }
}
