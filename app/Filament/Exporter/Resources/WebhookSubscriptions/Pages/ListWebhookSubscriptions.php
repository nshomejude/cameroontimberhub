<?php

namespace App\Filament\Exporter\Resources\WebhookSubscriptions\Pages;

use App\Filament\Exporter\Resources\WebhookSubscriptions\WebhookSubscriptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWebhookSubscriptions extends ListRecords
{
    protected static string $resource = WebhookSubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
