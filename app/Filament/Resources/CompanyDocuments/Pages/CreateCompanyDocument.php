<?php

namespace App\Filament\Resources\CompanyDocuments\Pages;

use App\Filament\Resources\CompanyDocuments\CompanyDocumentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCompanyDocument extends CreateRecord
{
    protected static string $resource = CompanyDocumentResource::class;
}
