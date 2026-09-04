<?php

namespace App\Filament\Resources\FraudSignals\Pages;

use App\Filament\Resources\FraudSignals\FraudSignalResource;
use Filament\Resources\Pages\ListRecords;

class ListFraudSignals extends ListRecords
{
    protected static string $resource = FraudSignalResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
