<?php

namespace App\Filament\Resources\VerificationRequests\Pages;

use App\Filament\Resources\VerificationRequests\VerificationRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVerificationRequest extends EditRecord
{
    protected static string $resource = VerificationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
