<?php

namespace App\Filament\Exporter\Resources\Leads\Pages;

use App\Filament\Exporter\Resources\Leads\LeadResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLead extends CreateRecord
{
    protected static string $resource = LeadResource::class;
}
