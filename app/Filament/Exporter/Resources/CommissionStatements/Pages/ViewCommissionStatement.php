<?php

namespace App\Filament\Exporter\Resources\CommissionStatements\Pages;

use App\Filament\Exporter\Resources\CommissionStatements\CommissionStatementResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCommissionStatement extends ViewRecord
{
    protected static string $resource = CommissionStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CommissionStatementResource::reportDepositAction(),
        ];
    }
}
