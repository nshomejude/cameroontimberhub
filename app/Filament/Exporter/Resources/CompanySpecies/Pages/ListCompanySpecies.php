<?php

namespace App\Filament\Exporter\Resources\CompanySpecies\Pages;

use App\Filament\Exporter\Resources\CompanySpecies\CompanySpeciesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanySpecies extends ListRecords
{
    protected static string $resource = CompanySpeciesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
