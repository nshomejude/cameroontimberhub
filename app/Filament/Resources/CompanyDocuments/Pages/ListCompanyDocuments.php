<?php

namespace App\Filament\Resources\CompanyDocuments\Pages;

use App\Filament\Resources\CompanyDocuments\CompanyDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanyDocuments extends ListRecords
{
    protected static string $resource = CompanyDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
