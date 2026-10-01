<?php

namespace App\Filament\Exporter\Resources\TransformationRequests\Pages;

use App\Filament\Exporter\Resources\TransformationRequests\Actions\TransformationRequestActions;
use App\Filament\Exporter\Resources\TransformationRequests\TransformationRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTransformationRequest extends ViewRecord
{
    protected static string $resource = TransformationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return TransformationRequestActions::all();
    }
}
