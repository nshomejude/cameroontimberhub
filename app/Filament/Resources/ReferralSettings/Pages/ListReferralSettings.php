<?php

namespace App\Filament\Resources\ReferralSettings\Pages;

use App\Filament\Resources\ReferralSettings\ReferralSettingResource;
use App\Models\ReferralSetting;
use Filament\Resources\Pages\ListRecords;

class ListReferralSettings extends ListRecords
{
    protected static string $resource = ReferralSettingResource::class;

    public function mount(): void
    {
        parent::mount();

        // Guarantee the single settings row exists so the table shows it.
        ReferralSetting::current();
    }
}
