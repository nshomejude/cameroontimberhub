<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SupportTicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupportTicketResource;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Staff support inbox. Gated by the `support.manage` permission
 * (SupportTicket::STAFF_PERMISSION) — held by super_admin, admin,
 * verification_officer, content_manager, compliance_officer,
 * support_officer and moderator. Anyone else gets 403.
 */
class StaffSupportTicketController extends Controller
{
    public function __construct(private readonly SupportTicketService $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeStaff($request);

        $request->validate([
            'status' => ['nullable', Rule::enum(SupportTicketStatus::class)],
        ]);

        $tickets = SupportTicket::query()
            ->with(['order', 'user'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $tickets->map(fn (SupportTicket $t) => (new SupportTicketResource($t))->withRequester()->resolve($request))->values(),
        ]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $this->authorizeStaff($request);

        $ticket = SupportTicket::query()->where('reference', $reference)->firstOrFail();

        return response()->json([
            'data' => (new SupportTicketResource($ticket->load(['order', 'user', 'messages.user'])))->withRequester(),
        ]);
    }

    public function reply(Request $request, string $reference): JsonResponse
    {
        $this->authorizeStaff($request);

        $ticket = SupportTicket::query()->where('reference', $reference)->firstOrFail();

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'status' => ['nullable', Rule::in(['pending', 'resolved', 'closed'])],
        ]);

        $this->tickets->staffReply(
            $ticket,
            $request->user(),
            $data['body'],
            isset($data['status']) ? SupportTicketStatus::from($data['status']) : null,
        );

        return response()->json([
            'message' => 'Reply sent.',
            'data' => (new SupportTicketResource($ticket->refresh()->load(['order', 'user', 'messages.user'])))->withRequester(),
        ], 201);
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless(SupportTicket::isStaff($request->user()), 403);
    }
}
