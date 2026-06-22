<?php

namespace App\Http\Controllers;

use App\Models\CompanyDocument;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a private compliance document. The route is signed (capability) AND
 * auth-gated; only the owning company's members or staff with documents.review
 * may download. Every download is recorded in document_access_logs.
 */
class DocumentDownloadController extends Controller
{
    public function __invoke(Request $request, CompanyDocument $document, DocumentService $documents): StreamedResponse
    {
        abort_unless($this->authorized($request->user(), $document), 403);

        return $documents->download($document, $request->user());
    }

    protected function authorized(?User $user, CompanyDocument $document): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->can('documents.review')) {
            return true;
        }

        return $user->companies()->whereKey($document->company_id)->exists();
    }
}
