<?php

namespace App\Filament\Resources\VerificationRequests\Pages;

use App\Filament\Resources\VerificationRequests\VerificationRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVerificationRequests extends ListRecords
{
    protected static string $resource = VerificationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
