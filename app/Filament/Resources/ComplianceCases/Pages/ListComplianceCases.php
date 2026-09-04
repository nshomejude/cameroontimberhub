<?php

namespace App\Filament\Resources\ComplianceCases\Pages;

use App\Filament\Resources\ComplianceCases\ComplianceCaseResource;
use Filament\Resources\Pages\ListRecords;

class ListComplianceCases extends ListRecords
{
    protected static string $resource = ComplianceCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
