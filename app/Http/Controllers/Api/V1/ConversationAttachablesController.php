<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Receipt;
use App\Models\Rfq;
use App\Models\User;
use App\Services\BuyerApiScope;
use App\Services\MessagingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/conversations/{id}/attachables?type=` — the chat paperclip.
 * Lists the CALLER's own records that involve this thread's counterparty, so
 * the mobile AttachSheet can share one as a reference:
 *
 *  - buyer side (the thread's `user_id`): orders/shipments placed with the
 *    thread's company, RFQs routed to it, receipts for orders with it;
 *  - supplier side (member of the thread's company): orders and quotes for
 *    that buyer, and that buyer's RFQs routed to the company.
 *
 * `type` takes the app's singular kinds (order|quote|rfq|receipt|shipment)
 * and the plural forms. A kind that does not apply to the caller's side
 * returns `data: []`. Row shape matches what AttachSheet reads:
 * {type, id, reference, title, status, status_label, total_amount, currency,
 * created_at}. Read-only; a non-participant 404s via MessagingService::find().
 */
class ConversationAttachablesController extends Controller
{
    private const LIMIT = 30;

    private const SHIPPED = [OrderStatus::Shipped, OrderStatus::Delivered];

    public function __construct(
        private readonly MessagingService $messaging,
        private readonly BuyerApiScope $buyerScope,
    ) {}

    public function index(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->messaging->find($user, $id);

        $type = rtrim(strtolower((string) $request->query('type', 'order')), 's');
        if (! in_array($type, ['order', 'quote', 'rfq', 'receipt', 'shipment'], true)) {
            throw new ApiException(422, 'validation_failed', 'Unknown attachable type.', ['type' => ['Use one of: order, quote, rfq, receipt, shipment.']]);
        }

        $isBuyer = (int) $conversation->user_id === (int) $user->getKey();

        $rows = $isBuyer
            ? $this->forBuyer($user, $conversation, $type)
            : $this->forSupplier($conversation, $type);

        return response()->json(['data' => $rows]);
    }

    /** @return list<array<string, mixed>> */
    private function forBuyer(User $user, Conversation $conversation, string $type): array
    {
        $companyId = $conversation->company_id;

        return match ($type) {
            'order', 'shipment' => $this->orders(
                Order::query()->where('user_id', $user->getKey())->where('company_id', $companyId),
                $type,
                'supplier_name',
            ),
            'rfq' => $this->rfqs(Rfq::query()->where('user_id', $user->getKey()), $companyId),
            'receipt' => $this->buyerScope->receipts($user)
                ->whereHas('order', fn (Builder $q) => $q->where('company_id', $companyId))
                ->with('order:id,reference_code')
                ->latest('id')->limit(self::LIMIT)->get()
                ->map(fn (Receipt $r) => [
                    'type' => 'receipt',
                    'id' => $r->getKey(),
                    'reference' => $r->receipt_number,
                    'receipt_number' => $r->receipt_number,
                    'title' => $r->order?->reference_code,
                    'order' => ['reference' => $r->order?->reference_code],
                    'status' => $r->voided_at ? 'voided' : 'issued',
                    'status_label' => $r->voided_at ? 'Voided' : 'Issued',
                    'total_amount' => $r->amount,
                    'currency' => $r->currency?->value,
                    'created_at' => $r->created_at?->toIso8601String(),
                ])->values()->all(),
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private function forSupplier(Conversation $conversation, string $type): array
    {
        $companyId = $conversation->company_id;
        $buyerId = $conversation->user_id;

        return match ($type) {
            'order', 'shipment' => $this->orders(
                Order::query()->where('company_id', $companyId)->where('user_id', $buyerId),
                $type,
                'buyer_name',
            ),
            'rfq' => $this->rfqs(Rfq::query()->where('user_id', $buyerId), $companyId),
            'quote' => Quote::query()
                ->where('company_id', $companyId)
                ->whereHas('rfq', fn (Builder $q) => $q->where('user_id', $buyerId))
                ->with('rfq:id,reference_code,title')
                ->latest('id')->limit(self::LIMIT)->get()
                ->map(fn (Quote $q) => [
                    'type' => 'quote',
                    'id' => $q->getKey(),
                    'reference' => $q->reference_code,
                    'title' => $q->rfq?->title ?: $q->rfq?->reference_code,
                    'rfq_reference' => $q->rfq?->reference_code,
                    'status' => $q->status?->value,
                    'status_label' => $q->status?->label(),
                    'total_amount' => $q->total_amount,
                    'currency' => $this->currency($q->currency),
                    'created_at' => $q->created_at?->toIso8601String(),
                ])->values()->all(),
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private function orders(Builder $query, string $type, string $titleColumn): array
    {
        if ($type === 'shipment') {
            $query->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, self::SHIPPED));
        }

        return $query->latest('id')->limit(self::LIMIT)->get()
            ->map(fn (Order $o) => [
                'type' => $type,
                'id' => $o->getKey(),
                'reference' => $o->reference_code,
                'title' => $o->{$titleColumn},
                'status' => $o->status?->value,
                'status_label' => $o->status?->label(),
                'total_amount' => $o->total_amount,
                'currency' => $this->currency($o->currency),
                'created_at' => $o->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function rfqs(Builder $query, int|string|null $companyId): array
    {
        return $query->whereHas('routings', fn (Builder $q) => $q->where('company_id', $companyId))
            ->latest('id')->limit(self::LIMIT)->get()
            ->map(fn (Rfq $r) => [
                'type' => 'rfq',
                'id' => $r->getKey(),
                'reference' => $r->reference_code,
                'title' => $r->title,
                'status' => $r->status?->value,
                'status_label' => $r->status?->label(),
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all();
    }

    private function currency(mixed $currency): ?string
    {
        return $currency instanceof \BackedEnum ? (string) $currency->value : ($currency !== null ? (string) $currency : null);
    }
}
