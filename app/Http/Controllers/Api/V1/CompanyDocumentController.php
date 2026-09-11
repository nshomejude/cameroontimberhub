<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCompanyDocumentRequest;
use App\Http\Resources\Api\V1\CompanyDocumentResource;
use App\Models\CompanyDocument;
use App\Models\DocumentType;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A supplier's own company's compliance documents, over token auth — the API
 * counterpart of the Filament exporter panel's Company Documents resource
 * (`App\Filament\Exporter\Resources\CompanyDocuments`), reshaped for the
 * mobile app.
 *
 * Sits behind the `api.supplier` gate (`EnsureApiSupplier`): only a user who
 * belongs to at least one company reaches this controller at all. "The
 * caller's company" is resolved as their first `company_user` membership —
 * the same population `EnsureApiSupplier` already checked exists, and the
 * only company a single-company supplier account has. A user who somehow
 * belongs to more than one company only ever sees/uploads against the first;
 * multi-company supplier accounts are out of scope here (none exist in the
 * product today).
 *
 * Uploads go through `DocumentService::store()` — the exact same service
 * `UploadCompanyDocument`/the Filament form use — so there is no second write
 * path, no second checksum/virus-scan-ready pipeline to keep in sync.
 *
 * `download()` streams the file directly through this authenticated API
 * endpoint via `DocumentService::download()`, rather than handing back the
 * web `documents.download` signed route: that web route sits behind session
 * `auth` middleware (see routes/web.php), which a Sanctum-token-only mobile
 * client never carries, so a signed link to it would be unusable from the
 * app. Streaming here reuses the exact same service method and access-log
 * call (`DocumentService::logAccess()` via `download()`), so audit coverage
 * is identical to the web download path.
 */
class CompanyDocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $company = $request->user()->companies()->first();

        $documents = $company
            ? $company->documents()->with('documentType')->latest('id')->get()
            : collect();

        return CompanyDocumentResource::collection($documents);
    }

    public function store(StoreCompanyDocumentRequest $request): JsonResponse
    {
        $company = $request->user()->companies()->firstOrFail();

        /** @var DocumentType $type */
        $type = DocumentType::query()->where('key', $request->validated('type'))->firstOrFail();

        $document = $this->documents->store(
            $company,
            $type,
            $request->file('file'),
            $request->user(),
        );

        return response()->json([
            'message' => 'Document uploaded.',
            'data' => new CompanyDocumentResource($document->load('documentType')),
        ], 201);
    }

    public function download(Request $request, CompanyDocument $document): StreamedResponse
    {
        abort_unless($request->user()->companies()->whereKey($document->company_id)->exists(), 404);

        return $this->documents->download($document, $request->user());
    }
}
