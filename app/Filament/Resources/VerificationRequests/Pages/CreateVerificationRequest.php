<?php

namespace App\Filament\Resources\VerificationRequests\Pages;

use App\Filament\Resources\VerificationRequests\VerificationRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVerificationRequest extends CreateRecord
{
    protected static string $resource = VerificationRequestResource::class;
}
