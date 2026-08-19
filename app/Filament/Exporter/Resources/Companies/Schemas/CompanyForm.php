<?php

namespace App\Filament\Exporter\Resources\Companies\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('legal_name')->required()->maxLength(255),
                        TextInput::make('trade_name')->maxLength(255)->helperText('Your public display name (optional).'),
                        Textarea::make('description')->rows(5)->required()->minLength(50)
                            ->helperText('At least 50 characters — shown on your public profile.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Branding')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('logo_path')->label('Logo')->image()->imageEditor()->disk('public')->directory('companies/logos'),
                        FileUpload::make('cover_path')->label('Cover image')->image()->imageEditor()->disk('public')->directory('companies/covers'),
                    ]),

                Section::make('Location')
                    ->columns(3)
                    ->schema([
                        TextInput::make('region')->required()->maxLength(120),
                        TextInput::make('city')->maxLength(120),
                        TextInput::make('country_code')->default('CM')->maxLength(2),
                        TextInput::make('address_line')->maxLength(255)->columnSpanFull(),
                        TextInput::make('email')->email()->maxLength(255),
                        TextInput::make('phone')->tel()->maxLength(32),
                        TextInput::make('website_url')->url()->maxLength(255),
                    ]),

                Section::make('Species handled')
                    ->schema([
                        Select::make('species')
                            ->relationship('species', 'common_name')
                            ->multiple()->preload()->searchable()
                            ->helperText('Select the species you supply. At least one is required to be listed publicly.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Export markets')
                    ->schema([
                        Repeater::make('exportMarkets')
                            ->relationship()
                            ->schema([
                                TextInput::make('country_code')->required()->maxLength(2)->placeholder('e.g. FR'),
                            ])
                            ->addActionLabel('Add market')
                            ->columnSpanFull(),
                    ]),

                Section::make('Contacts')
                    ->schema([
                        Repeater::make('contacts')
                            ->relationship()
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')->required(),
                                TextInput::make('title'),
                                TextInput::make('email')->email(),
                                TextInput::make('phone')->tel(),
                                TextInput::make('whatsapp')->tel(),
                                Toggle::make('is_public')->default(true)->helperText('Show on public profile'),
                            ])
                            ->addActionLabel('Add contact')
                            ->helperText('At least one contact is required to be listed publicly.')
                            ->columnSpanFull(),
                    ]),

                /*
                 * Settlement instructions shown on the buyer's payment-request
                 * card.
                 *
                 * ⚠ This marketplace processes no payments and holds no funds.
                 * This is free text the supplier maintains so a buyer can pay
                 * them directly; the platform neither validates it, uses it,
                 * nor transmits anything to a bank. It is shown only to a buyer
                 * who has an order with this company, never on a public page.
                 */
                Section::make('Payment instructions')
                    ->collapsed()
                    ->schema([
                        Textarea::make('payment_instructions')
                            ->label('How buyers should pay you')
                            ->rows(5)
                            ->maxLength(2000)
                            ->helperText('Shown to a buyer alongside a payment request on one of your orders. Cameroon Timber Hub does not process payments — buyers settle with you directly. Do not paste anything here you would not want an existing customer to read.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Gallery')
                    ->collapsed()
                    ->schema([
                        Repeater::make('gallery')
                            ->relationship()
                            ->columns(2)
                            ->schema([
                                FileUpload::make('image_path')->image()->disk('public')->directory('companies/gallery')->required(),
                                TextInput::make('caption'),
                            ])
                            ->addActionLabel('Add image')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
