<?php

namespace App\Notifications;

use App\Models\TransformationRequest;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the requester's company users when the provider quotes the job —
 * TransformationRequestService::quote() is the trigger.
 */
class TransformationRequestQuotedNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'transformation_request_quoted';

    public function __construct(public TransformationRequest $request) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $providerName = $this->request->providerCompany?->name ?? 'The provider';

        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.transformation_request_quoted.title'),
            'body' => __('notifications.push.transformation_request_quoted.body', [
                'provider' => $providerName,
                'request' => $this->request->reference_code,
            ]),
            'reference' => $this->request->reference_code,
            'screen' => 'transformation_request',
            'quote_amount' => (string) $this->request->quote_amount,
            'quote_currency' => $this->request->quote_currency,
        ];
    }
}
