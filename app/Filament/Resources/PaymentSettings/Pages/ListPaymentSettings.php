<?php

namespace App\Filament\Resources\PaymentSettings\Pages;

use App\Enums\PaymentProvider;
use App\Filament\Resources\PaymentSettings\PaymentSettingResource;
use App\Models\PaymentSetting;
use Filament\Resources\Pages\ListRecords;

class ListPaymentSettings extends ListRecords
{
    protected static string $resource = PaymentSettingResource::class;

    public function mount(): void
    {
        parent::mount();

        // Ensure there is exactly one row per provider so the table always
        // shows the full set (mirrors AiSetting::forProvider firstOrCreate).
        foreach (PaymentProvider::cases() as $provider) {
            PaymentSetting::forProvider($provider);
        }
    }
}
