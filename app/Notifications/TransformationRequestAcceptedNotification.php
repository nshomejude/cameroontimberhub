<?php

namespace App\Notifications;

use App\Models\TransformationRequest;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the provider's company users when the requester accepts (either
 * the job directly, Pending -> Accepted, or the quote, Quoted -> Accepted) —
 * TransformationRequestService::accept()/acceptQuote() is the trigger.
 */
class TransformationRequestAcceptedNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'transformation_request_accepted';

    public function __construct(public TransformationRequest $request) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $requesterName = $this->request->requesterCompany?->name ?? 'The requester';

        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.transformation_request_accepted.title'),
            'body' => __('notifications.push.transformation_request_accepted.body', [
                'requester' => $requesterName,
                'request' => $this->request->reference_code,
            ]),
            'reference' => $this->request->reference_code,
            'screen' => 'transformation_request',
        ];
    }
}
