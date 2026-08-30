<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Enums\SupplierType;
use App\Enums\VerificationTier;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('legal_name')->required()->maxLength(255),
                        TextInput::make('trade_name')->maxLength(255)->helperText('Public display name (falls back to legal name).'),
                        TextInput::make('slug')->maxLength(180)->unique(ignoreRecord: true)
                            ->helperText('Leave blank to auto-generate. Changing a live slug breaks inbound links.'),
                        TextInput::make('registration_number')->label('RCCM')->maxLength(100),
                        TextInput::make('tax_id')->label('NIU')->maxLength(100),
                    ]),

                Section::make('Profile')
                    ->columns(3)
                    ->schema([
                        Textarea::make('description')->rows(5)->columnSpanFull(),
                        TextInput::make('year_founded')->numeric()->minValue(1800)->maxValue((int) date('Y')),
                        TextInput::make('employee_count')->numeric()->minValue(0),
                        TextInput::make('annual_capacity_m3')->label('Annual capacity (m³)')->numeric(),
                    ]),

                Section::make('Directory listing')
                    ->description('Drives the supplier-type facet and the metric strip on public directory cards. Leave a metric blank to hide it.')
                    ->columns(3)
                    ->schema([
                        Select::make('supplier_type')
                            ->label('Supplier type')
                            ->options(SupplierType::options())
                            ->native(false)
                            ->placeholder('Not classified'),
                        TextInput::make('response_rate_percent')
                            ->label('Response rate (%)')
                            ->numeric()->minValue(0)->maxValue(100)
                            ->helperText('Share of buyer inquiries answered.'),
                        TextInput::make('years_experience')
                            ->label('Years of experience')
                            ->numeric()->minValue(0)->maxValue(200),
                    ]),

                Section::make('Trust metrics')
                    ->description('Shown on the "Supplier Information" card of every product detail page. Each figure is hidden when blank — never enter a placeholder.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('rating_avg')
                            ->label('Buyer rating (0–5)')
                            ->numeric()->minValue(0)->maxValue(5)->step(0.1)
                            ->helperText('Only shown together with a review count.'),
                        TextInput::make('rating_count')
                            ->label('Ratings received')
                            ->numeric()->minValue(0),
                        TextInput::make('orders_completed')
                            ->label('Orders completed')
                            ->numeric()->minValue(0),
                        TextInput::make('response_time_hours')
                            ->label('Typical response time (hours)')
                            ->numeric()->minValue(1)->maxValue(720),
                        TagsInput::make('languages')
                            ->label('Trade desk languages')
                            ->placeholder('Add a language')
                            ->columnSpan(2),
                    ]),

                Section::make('Public profile details')
                    ->description('Powers the Business Summary, Forest & Sourcing and Logistics panels on the public supplier profile. Each row is hidden when blank — never enter a placeholder.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('tagline')->maxLength(160)->helperText('Short strapline under the company name.')->columnSpanFull(),
                        TextInput::make('on_time_delivery_percent')->label('On-time delivery (%)')->numeric()->minValue(0)->maxValue(100),
                        TextInput::make('payment_terms')->maxLength(255),
                        TextInput::make('working_hours')->maxLength(120)->placeholder('Mon – Fri: 8:00 AM – 5:00 PM'),
                        TextInput::make('main_ports')->maxLength(255)->placeholder('Douala, Kribi'),
                        TextInput::make('shipping_terms')->maxLength(120)->placeholder('FOB, CFR, CIF'),
                        TextInput::make('delivery_days_min')->label('Delivery time — min (days)')->numeric()->minValue(0),
                        TextInput::make('delivery_days_max')->label('Delivery time — max (days)')->numeric()->minValue(0),
                        TextInput::make('forest_location')->maxLength(255),
                        TextInput::make('forest_management')->maxLength(255),
                        TextInput::make('annual_harvest_capacity_m3')->label('Annual harvest capacity (m³)')->numeric(),
                    ]),

                Section::make('Trust tier (blueprint §4)')
                    ->description('Tier 1 (Identity Verified) is derived automatically once the company passes the existing verification workflow. Tiers 2-5 have no automated evidence source yet and are administratively asserted here until operational-document review, site visits and lot inspections are built. A company that is not yet verified is hard-capped at Unverified regardless of what is selected.')
                    ->visible(fn () => auth()->user()?->can('verification.review') ?? false)
                    ->columns(1)
                    ->schema([
                        Select::make('verification_tier')
                            ->label('Verification tier')
                            ->options(VerificationTier::options())
                            ->native(false)
                            ->formatStateUsing(fn ($state) => $state instanceof VerificationTier ? $state->value : $state)
                            ->dehydrateStateUsing(fn ($state) => (int) $state)
                            ->helperText(fn ($state) => VerificationTier::tryFrom((int) $state)?->scopeDescription() ?? '')
                            ->live(),
                    ]),

                Section::make('Location & contact')
                    ->columns(3)
                    ->schema([
                        TextInput::make('region')->maxLength(120),
                        TextInput::make('city')->maxLength(120),
                        TextInput::make('country_code')->default('CM')->maxLength(2),
                        TextInput::make('address_line')->maxLength(255)->columnSpanFull(),
                        TextInput::make('email')->email()->maxLength(255),
                        TextInput::make('phone')->tel()->maxLength(32),
                        TextInput::make('website_url')->url()->maxLength(255),
                    ]),

                Section::make('Branding')
                    ->columns(2)
                    ->schema([
                        FileUpload::make('logo_path')->image()->imageEditor()->disk('public')->directory('companies/logos'),
                        FileUpload::make('cover_path')->image()->imageEditor()->disk('public')->directory('companies/covers'),
                    ]),

                Section::make('SIGIF (captured, not integrated)')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextInput::make('sigif_operator_id')->label('SIGIF operator ID')->maxLength(100),
                        TagsInput::make('sigif_permit_numbers')->label('Permit / title numbers')->placeholder('Add a number'),
                    ]),

                Section::make('SEO')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextInput::make('meta_title')->maxLength(255),
                        Textarea::make('meta_description')->rows(2)->maxLength(320),
                    ]),
            ]);
    }
}
