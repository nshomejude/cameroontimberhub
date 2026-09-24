<?php

namespace App\Notifications;

use App\Models\TransformationRequest;
use App\Notifications\Concerns\PreferenceGatedChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired at the requester's company users when the provider completes the
 * job — TransformationRequestService::completeJob() is the trigger, after
 * the real LotTransformation ledger row has been recorded.
 */
class TransformationRequestCompletedNotification extends Notification implements ShouldQueue
{
    use PreferenceGatedChannels;
    use Queueable;

    public const TYPE = 'transformation_request_completed';

    public function __construct(public TransformationRequest $request) {}

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $providerName = $this->request->providerCompany?->name ?? 'The provider';

        return [
            'type' => self::TYPE,
            'title' => __('notifications.push.transformation_request_completed.title'),
            'body' => __('notifications.push.transformation_request_completed.body', [
                'provider' => $providerName,
                'request' => $this->request->reference_code,
            ]),
            'reference' => $this->request->reference_code,
            'screen' => 'transformation_request',
            'lot_transformation_id' => $this->request->lot_transformation_id,
        ];
    }
}
