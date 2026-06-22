<?php

namespace App\Filament\Resources\Rfqs\Pages;

use App\Filament\Resources\Rfqs\RfqResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditRfq extends EditRecord
{
    protected static string $resource = RfqResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
