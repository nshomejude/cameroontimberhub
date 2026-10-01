<?php

namespace App\Filament\Resources\CommissionStatements\Pages;

use App\Filament\Resources\CommissionStatements\CommissionStatementResource;
use App\Filament\Resources\CommissionStatements\Support\CommissionCollectionActions;
use Filament\Resources\Pages\ViewRecord;

class ViewCommissionStatement extends ViewRecord
{
    protected static string $resource = CommissionStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CommissionCollectionActions::recordDeposit(),
            CommissionCollectionActions::voidStatement(),
        ];
    }
}
