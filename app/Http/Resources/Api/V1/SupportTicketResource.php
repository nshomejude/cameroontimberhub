<?php

namespace App\Http\Resources\Api\V1;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Support ticket shape the mobile app is built against. `messages` only
 * appears when the relation is loaded (show/store/reply), `requester` only
 * on the staff endpoints (set via withRequester()).
 *
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    private bool $withRequester = false;

    public function withRequester(bool $on = true): static
    {
        $this->withRequester = $on;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewerId = (int) $request->user()?->getKey();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'category' => $this->category->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'order_reference' => $this->order?->reference_code,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'requester' => $this->when($this->withRequester, fn () => [
                'name' => $this->user?->name,
                'company' => $this->user ? UserResource::resolveCompany($this->user)['name'] ?? null : null,
            ]),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn (SupportTicketMessage $m) => [
                'id' => $m->id,
                'body' => $m->body,
                'created_at' => $m->created_at?->toIso8601String(),
                'is_own' => $viewerId !== 0 && (int) $m->user_id === $viewerId,
                'sender' => [
                    'name' => $m->is_staff ? ($m->user?->name ?? 'Support') : ($m->user?->name ?? 'User'),
                    'is_staff' => (bool) $m->is_staff,
                ],
            ])->values()->all()),
        ];
    }
}
