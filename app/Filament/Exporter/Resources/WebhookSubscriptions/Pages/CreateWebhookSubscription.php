<?php

namespace App\Filament\Exporter\Resources\WebhookSubscriptions\Pages;

use App\Filament\Exporter\Resources\WebhookSubscriptions\WebhookSubscriptionResource;
use App\Models\WebhookSubscription;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Generates the plaintext signing secret on creation and shows it exactly
 * once. The secret is stored encrypted at rest (`secret`, via the model's
 * `encrypted` cast) and used directly as the HMAC key — Stripe/GitHub
 * `whsec_...` style. Same one-time-reveal UX as an API key (see
 * App\Filament\Resources\ApiKeyIssuanceRequests's table action, which also
 * shows a plaintext token once via a persistent Notification).
 */
class CreateWebhookSubscription extends CreateRecord
{
    protected static string $resource = WebhookSubscriptionResource::class;

    private string $plainTextSecret;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $company = auth()->user()->companies()->firstOrFail();

        $this->plainTextSecret = WebhookSubscription::generatePlainTextSecret();

        $data['company_id'] = $company->getKey();
        $data['created_by'] = auth()->id();
        $data['secret'] = $this->plainTextSecret;

        return $data;
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Webhook secret (shown once)')
            ->body('Store this secret now — it will never be shown again: '.$this->plainTextSecret)
            ->success()
            ->persistent()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index', panel: 'exporter');
    }
}
