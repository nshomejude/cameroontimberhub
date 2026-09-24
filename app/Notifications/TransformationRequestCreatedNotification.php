<?php

namespace App\Notifications;

use App\Models\TransformationRequest;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the provider's company users when a requester company creates a
 * new transformation request — TransformationRequestService::create() is
 * the trigger.
 */
class TransformationRequestCreatedNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'transformation_request_created';

    public function __construct(public TransformationRequest $request) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $requesterName = $this->request->requesterCompany?->name ?? 'A company';

        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.transformation_request_created.title'),
            'body' => __('notifications.push.transformation_request_created.body', [
                'requester' => $requesterName,
                'request' => $this->request->reference_code,
            ]),
            'reference' => $this->request->reference_code,
            'screen' => 'transformation_request',
            'service' => $this->request->service,
        ];
    }
}
