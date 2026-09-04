<?php

namespace App\Filament\Exporter\Resources\InspectionRequests\Schemas;

use App\Models\TimberLot;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InspectionRequestForm
{
    /** The 10 blueprint §27 inspection types (mirrors the DB check constraint). */
    public const INSPECTION_TYPES = [
        'pre_production' => 'Pre-production',
        'pre_shipment' => 'Pre-shipment',
        'loading' => 'Loading',
        'quantity' => 'Quantity',
        'moisture' => 'Moisture',
        'species_identification' => 'Species identification',
        'quality_grade' => 'Quality/grade',
        'packaging' => 'Packaging',
        'document_cross_check' => 'Document cross-check',
        'site' => 'Site',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Request an inspection')
                    ->description("We'll match an eligible inspector automatically where one is available. Otherwise our team will assign one shortly.")
                    ->columns(2)
                    ->schema([
                        Select::make('timber_lot_id')
                            ->label('Timber lot')
                            ->options(function () {
                                $company = auth()->user()?->companies()->first();

                                if (! $company) {
                                    return [];
                                }

                                return TimberLot::query()
                                    ->forCompany($company->getKey())
                                    ->pluck('lot_number', 'id');
                            })
                            ->searchable()
                            ->required()
                            ->helperText('Only lots belonging to your company are shown.'),
                        Select::make('inspection_type')
                            ->options(self::INSPECTION_TYPES)
                            ->native(false)
                            ->required(),
                        DatePicker::make('scheduled_for')
                            ->label('Preferred date')
                            ->native(false)
                            ->minDate(today())
                            ->required(),
                    ]),
            ]);
    }
}
