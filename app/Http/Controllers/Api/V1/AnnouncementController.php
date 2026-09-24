<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public, unauthenticated feed for the mobile app home screen. Always
 * `{"data": [...]}` — an empty result is `{"data": []}`, never an error.
 */
class AnnouncementController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $announcements = Announcement::query()
            ->active()
            ->orderBy('sort_order')
            ->get();

        return AnnouncementResource::collection($announcements);
    }
}
