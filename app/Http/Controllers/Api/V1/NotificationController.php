<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `/api/v1/notifications` — any authenticated user's own database
 * notifications (buyer, supplier or staff; a notification's `notifiable_id`
 * scopes everything here to the caller, exactly like `MessagingService::
 * find()` scopes conversations).
 */
class NotificationController extends Controller
{
    /** Newest-first, paginated — mirrors `ConversationController::index()`'s envelope. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return NotificationResource::collection($notifications);
    }

    /**
     * One notification, full detail. Scoped to the caller's own the same
     * way `read()` is — a stranger's id 404s rather than 403s.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->whereKey($id)
            ->firstOrFail();

        return response()->json(['data' => new NotificationResource($notification)]);
    }

    /**
     * Mark one read. Scoped to the caller's own notifications via the
     * relation query itself — a stranger's id simply does not resolve, so
     * this 404s rather than 403s (enumeration-safety, same boundary
     * `MessagingService` uses for conversations).
     */
    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()
            ->notifications()
            ->whereKey($id)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json(['data' => new NotificationResource($notification->fresh())]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->each->markAsRead();

        return response()->json(['message' => 'All notifications marked read.']);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['count' => $request->user()->unreadNotifications()->count()]]);
    }
}
