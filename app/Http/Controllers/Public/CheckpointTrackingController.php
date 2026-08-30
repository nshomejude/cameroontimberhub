<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\CheckpointTracker;
use Illuminate\View\View;

/**
 * The public checkpoint-tracking page (gap-plan 1.5.11). Mirrors
 * ReceiptVerificationController::token() — a bare token in the URL is looked
 * up and rendered directly (there is no printed "tracking number" to type by
 * hand the way there is a receipt number, so there is no separate search
 * form). Every fact rendered comes from CheckpointTracker::publicPayload(),
 * an allow-list.
 */
class CheckpointTrackingController extends Controller
{
    public function __construct(private readonly CheckpointTracker $tracker) {}

    public function show(string $token): View
    {
        $history = $this->tracker->findByToken($token);

        return view('public.tracking.show', [
            'checkpoints' => $this->tracker->publicPayload($history),
        ]);
    }
}
