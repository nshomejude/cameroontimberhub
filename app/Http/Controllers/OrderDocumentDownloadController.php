<?php

namespace App\Http\Controllers;

use App\Models\OrderDocument;
use App\Services\OrderDocumentService;
use App\Services\OrderLifecycleService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to an order document's bytes.
 *
 * Follows DocumentDownloadController's pattern: auth middleware on the route,
 * then an explicit authorisation check here, then the service streams from the
 * private disk. The files live under storage/app/documents, which is outside
 * the public root — there is no URL that reaches them directly, guessable or
 * otherwise, so nothing depends on the id being hard to guess.
 *
 * 404, not 403, for an unauthorised requester: whether an order document with
 * a given id exists is not something a stranger should be able to probe, and
 * this matches the 404-not-403 rule the rest of messaging follows.
 */
class OrderDocumentDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        OrderDocument $document,
        OrderLifecycleService $lifecycle,
        OrderDocumentService $documents,
    ): StreamedResponse {
        $document->loadMissing('order');

        abort_unless($lifecycle->mayAccessDocument($request->user(), $document), 404);

        return $documents->download($document);
    }
}
