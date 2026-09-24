<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Conversation;
use App\Models\User;
use App\Services\MessagingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbox row for the buyer mobile app — the API counterpart of an inbox
 * list item in `App\Livewire\Messaging\Inbox`.
 *
 * `counterparty` is resolved from the VIEWER's side, exactly like
 * `Conversation::counterpartyName()`/`counterpartyLogoUrl()` already do for
 * the web thread — this endpoint has been open to any authenticated
 * participant (buyer or supplier) since messaging was widened past
 * buyer-only, so hardcoding "the other side is always company_id" here would
 * show a supplier their OWN company as the counterparty instead of the
 * buyer. When the viewer IS the buyer (`user_id` matches), the counterparty
 * is the supplier company (`SupplierResource`, reused rather than inventing
 * a second supplier-card shape); when the viewer is a member of the supplier
 * company, the counterparty is the buyer account (a minimal inline shape —
 * there is no dedicated buyer-profile resource, and a buyer has no company/
 * logo to show).
 *
 * `unread_count` is real, derived state from `MessagingService::unreadCounts()`
 * — never a stored counter. The controller computes the whole page's counts
 * in ONE grouped query and stamps each model with a `unread_count` attribute
 * before wrapping it here (see `ConversationController::withUnreadCounts()`);
 * if that was not done (e.g. a single `show()` conversation), this falls back
 * to a single-conversation lookup so the field is never wrong, just less batched.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $latest = $this->latestMessage;
        $viewerIsBuyer = $user && (int) $this->user_id === (int) $user->getKey();

        $unread = $this->resource->getAttribute('unread_count')
            ?? app(MessagingService::class)->unreadCounts($user, [$this->id])[$this->id]
            ?? 0;

        return [
            'id' => $this->id,
            'subject' => $this->subjectLine(),
            'topic' => $this->topic?->value,
            'status' => $this->status?->value,
            'counterparty' => $viewerIsBuyer
                ? $this->whenLoaded('company', fn () => $this->company ? new SupplierResource($this->company) : null)
                : $this->whenLoaded('user', fn () => $this->user ? [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ] : null),
            'last_message' => $latest ? [
                'body' => $latest->preview(160),
                'kind' => $latest->type->value,
                'at' => $latest->created_at?->toIso8601String(),
                'sender' => [
                    'id' => $latest->sender_user_id,
                    'name' => $latest->sender?->name,
                    'is_own' => $latest->isFrom($user),
                ],
            ] : null,
            'unread_count' => $unread,
            'composer_actions' => $user ? $this->composerActions($user) : [],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The "+" composer trigger(s) available to this viewer on this thread.
     *
     * `thread.blade.php` gates the RFQ composer with `@if ($isBuyer)` alone —
     * no "only if there is no open RFQ yet" condition exists there, so this
     * mirrors exactly that: every buyer sees `create_rfq` on every thread,
     * unconditionally.
     *
     * The web composer has no supplier-side trigger of its own: a supplier
     * issues a quotation from the exporter panel (`QuoteController`), not
     * from a "+" menu in the thread — `ChatCommerceService::issueQuotation()`
     * is called from there, never from `Thread`'s composer. So a supplier
     * gets `[]` here, not an invented `send_quotation` item; see this
     * class's calling controller's docblock / the task report for why.
     *
     * @return list<array{key: string, label: string, method: string, path: string}>
     */
    private function composerActions(User $user): array
    {
        $isBuyer = (int) $this->user_id === (int) $user->getKey();

        if (! $isBuyer) {
            return [];
        }

        return [[
            'key' => 'create_rfq',
            'label' => 'Request for quote',
            'method' => 'POST',
            'path' => "conversations/{$this->id}/rfq",
            'fields' => [
                ['name' => 'species_text', 'label' => 'Species', 'type' => 'text', 'required' => true, 'max_length' => 180],
                ['name' => 'quantity', 'label' => 'Quantity', 'type' => 'decimal', 'required' => true],
                ['name' => 'unit', 'label' => 'Unit', 'type' => 'select', 'required' => true, 'options' => collect(\App\Enums\RfqUnit::options())->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all()],
                ['name' => 'form', 'label' => 'Form', 'type' => 'select', 'required' => false, 'options' => collect(\App\Enums\TimberForm::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->value])->all()],
                ['name' => 'grade', 'label' => 'Grade', 'type' => 'text', 'required' => false, 'max_length' => 60],
                ['name' => 'dimensions', 'label' => 'Dimensions', 'type' => 'text', 'required' => false, 'max_length' => 255],
                ['name' => 'moisture_content', 'label' => 'Moisture content', 'type' => 'text', 'required' => false, 'max_length' => 60],
                ['name' => 'incoterm', 'label' => 'Incoterm', 'type' => 'select', 'required' => false, 'options' => collect(\App\Enums\RfqIncoterm::cases())->map(fn ($case) => ['value' => $case->value, 'label' => $case->value])->all()],
                ['name' => 'shipping_port', 'label' => 'Shipping port', 'type' => 'text', 'required' => false, 'max_length' => 120],
                ['name' => 'deadline', 'label' => 'Deadline', 'type' => 'date', 'required' => false],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => false, 'max_length' => 500],
            ],
        ]];
    }
}
