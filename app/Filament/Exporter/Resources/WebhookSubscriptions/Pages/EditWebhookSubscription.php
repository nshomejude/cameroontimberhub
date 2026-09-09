<?php

namespace App\Filament\Exporter\Resources\WebhookSubscriptions\Pages;

use App\Filament\Exporter\Resources\WebhookSubscriptions\WebhookSubscriptionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWebhookSubscription extends EditRecord
{
    protected static string $resource = WebhookSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index', panel: 'exporter');
    }
}
