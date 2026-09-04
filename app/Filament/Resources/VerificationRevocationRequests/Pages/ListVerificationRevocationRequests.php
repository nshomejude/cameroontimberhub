<?php

namespace App\Filament\Resources\VerificationRevocationRequests\Pages;

use App\Filament\Resources\VerificationRevocationRequests\VerificationRevocationRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListVerificationRevocationRequests extends ListRecords
{
    protected static string $resource = VerificationRevocationRequestResource::class;
}
