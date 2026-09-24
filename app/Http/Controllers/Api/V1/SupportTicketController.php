<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SupportTicketCategory;
use App\Exceptions\Api\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupportTicketResource;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * "Live chat with support" for any authenticated user. A ticket is only ever
 * visible to its owner: someone else's reference 404s (never 403s), the same
 * enumeration-safety convention as BuyerApiScope.
 */
class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::query()
            ->with('order')
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get();

        return response()->json(['data' => SupportTicketResource::collection($tickets)->resolve($request)]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $ticket = $this->own($request, $reference);

        return response()->json(['data' => new SupportTicketResource($ticket->load(['order', 'messages.user']))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', Rule::enum(SupportTicketCategory::class)],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
            'order_reference' => ['nullable', 'string', 'max:64'],
        ]);

        $order = null;
        if (! empty($data['order_reference'])) {
            $order = $this->tickets->visibleOrder($request->user(), $data['order_reference']);

            if ($order === null) {
                throw ValidationException::withMessages([
                    'order_reference' => __('validation.exists', ['attribute' => 'order reference']),
                ]);
            }
        }

        $ticket = $this->tickets->open(
            $request->user(),
            $data['subject'],
            SupportTicketCategory::from($data['category']),
            $data['body'],
            $order,
        );

        return response()->json([
            'message' => 'Ticket opened.',
            'data' => new SupportTicketResource($ticket->load(['order', 'messages.user'])),
        ], 201);
    }

    public function reply(Request $request, string $reference): JsonResponse
    {
        $ticket = $this->own($request, $reference);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        try {
            $this->tickets->userReply($ticket, $request->user(), $data['body']);
        } catch (RuntimeException $e) {
            throw new ConflictException($e->getMessage(), 'ticket_closed', $e);
        }

        return response()->json([
            'message' => 'Reply sent.',
            'data' => new SupportTicketResource($ticket->refresh()->load(['order', 'messages.user'])),
        ], 201);
    }

    private function own(Request $request, string $reference): SupportTicket
    {
        return SupportTicket::query()
            ->where('user_id', $request->user()->getKey())
            ->where('reference', $reference)
            ->firstOrFail();
    }
}
