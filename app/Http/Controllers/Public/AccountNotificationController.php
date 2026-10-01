<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/account/notifications` — the buyer's notification centre on the web. Same
 * query as Api\V1\NotificationController (the user's own `notifications()`
 * relation, newest first, 20 per page), so a stranger's id never resolves:
 * mark-read 404s rather than 403s. Each row is presented through the API's
 * NotificationResource so title/body/icon are derived in one place.
 */
class AccountNotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('public.account.notifications', [
            'notifications' => $notifications,
            'rows' => collect($notifications->items())
                ->map(fn ($n) => (new NotificationResource($n))->toArray($request)),
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return back()->with('status', __('messages.account_center.notification_marked_read'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('status', __('messages.account_center.notifications_all_read'));
    }
}
