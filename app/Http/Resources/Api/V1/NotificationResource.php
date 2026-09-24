<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One database notification for the mobile app's notification center.
 *
 * `title`/`body`/`reference`/`screen` are pulled out of the notification's
 * stored `data` JSON — every notification class in `App\Notifications`
 * (Quote/OrderStatusChanged/MessageReceived/DisputeReply) writes exactly
 * those keys via `toArray()`, so nothing here invents a field the writer
 * did not actually store. `type` is the short machine key each class stores
 * at `data['type']` (e.g. `quote_received`), not the notification's FQCN.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];

        return [
            'id' => $this->id,
            'type' => $data['type'] ?? null,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'reference' => $data['reference'] ?? null,
            'screen' => $data['screen'] ?? null,
        ];
    }
}
