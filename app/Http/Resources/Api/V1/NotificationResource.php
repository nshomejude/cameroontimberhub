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
 * writes exactly those keys via `toArray()`, so nothing here invents a
 * field the writer did not actually store. `type` is the short machine key
 * each class stores at `data['type']` (e.g. `quote_received`), not the
 * notification's FQCN.
 *
 * `data` surfaces whatever EXTRA keys a given type's `toArray()` wrote
 * beyond the surfaced fields (e.g. `from_status`/`to_status` on
 * `order_status_changed`) — nothing is invented here either; it is exactly
 * `data` minus the keys already promoted to top-level fields.
 *
 * `icon`/`tone` are a presentation convenience computed from `type`,
 * mirroring the icon convention already used by
 * `DashboardController::activity()`/`stats()` (Heroicon outline names, e.g.
 * `document-text`). A type this map does not know falls back to a neutral
 * bell icon and `default` tone rather than omitting the fields.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /** @var array<string, string> */
    private const ICONS = [
        'quote_received' => 'document-text',
        'quote_accepted' => 'check-circle',
        'quote_declined' => 'x-circle',
        'counter_offer' => 'arrows-right-left',
        'order_status_changed' => 'clipboard-document-check',
        'payment_requested' => 'banknotes',
        'payment_confirmed' => 'banknotes',
        'shipment_update' => 'truck',
        'document_uploaded' => 'paper-clip',
        'message_received' => 'chat-bubble-left-right',
        'dispute_reply' => 'exclamation-triangle',
        'dispute_opened' => 'exclamation-triangle',
        'rfq_routed' => 'inbox-arrow-down',
    ];

    /** @var array<string, string> */
    private const TONES = [
        'quote_accepted' => 'success',
        'quote_declined' => 'warning',
        'payment_confirmed' => 'success',
        'dispute_reply' => 'warning',
        'dispute_opened' => 'warning',
    ];

    /** Fields already surfaced at the top level — everything else in `data` is passed through under `data`. */
    private const PROMOTED_KEYS = ['type', 'title', 'body', 'reference', 'screen'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->data ?? [];
        $type = $data['type'] ?? null;

        return [
            'id' => $this->id,
            'type' => $type,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'reference' => $data['reference'] ?? null,
            'screen' => $data['screen'] ?? null,
            'data' => array_diff_key($data, array_flip(self::PROMOTED_KEYS)),
            'icon' => self::ICONS[$type] ?? 'bell',
            'tone' => self::TONES[$type] ?? 'default',
        ];
    }
}
