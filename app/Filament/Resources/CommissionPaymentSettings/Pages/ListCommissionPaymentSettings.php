<?php

namespace App\Filament\Resources\CommissionPaymentSettings\Pages;

use App\Filament\Resources\CommissionPaymentSettings\CommissionPaymentSettingResource;
use App\Models\CommissionPaymentSetting;
use Filament\Resources\Pages\ListRecords;

class ListCommissionPaymentSettings extends ListRecords
{
    protected static string $resource = CommissionPaymentSettingResource::class;

    public function mount(): void
    {
        parent::mount();

        // Guarantee the single settings row exists so the table shows it.
        CommissionPaymentSetting::current();
    }
}
