<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\CompanyStatus;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Agent\AgentSupplierResource;
use App\Models\Company;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentIngestionService;
use App\Support\Agent\AgentPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Agent Ingestion Gateway — supplier endpoints (docs/api/AGENT_INGESTION.md).
 * All writes go through AgentIngestionService; this class only shapes HTTP.
 *
 * @tags Agent ingestion
 */
class AgentSupplierController extends Controller
{
    public const BATCH_MAX_SUPPLIERS = 50;

    public const BATCH_MAX_PRODUCTS = 50;

    public function __construct(private readonly AgentIngestionService $ingestion) {}

    /**
     * Upsert a supplier by `external_id`.
     *
     * 201 `result: created`, 200 `result: updated`, 200 `result: duplicate`
     * (an existing agent-sourced supplier matched — use `existing_id`),
     * 409 `supplier_owned` / `supplier_locked`, 422 validation.
     */
    public function store(Request $request): JsonResponse
    {
        $outcome = $this->ingestion->upsertSupplier($request->all(), AgentContext::fromRequest($request));

        return $this->respond($outcome);
    }

    /** Batch upsert of up to 50 suppliers, each with up to 50 nested products. Always HTTP 200 with per-item results. */
    public function batch(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'suppliers' => ['required', 'array', 'min:1', 'max:'.self::BATCH_MAX_SUPPLIERS],
            'suppliers.*' => ['array'],
            'suppliers.*.products' => ['nullable', 'array', 'max:'.self::BATCH_MAX_PRODUCTS],
            'dry_run' => ['nullable', 'boolean'],
        ])->validate();

        $ctx = AgentContext::fromRequest($request);
        $dryRun = $request->boolean('dry_run');
        $results = [];

        foreach (array_values($request->input('suppliers')) as $index => $item) {
            $results[] = $this->batchItem($index, (array) $item, $ctx, $dryRun);
        }

        $summary = collect($results)->countBy('result')->all();

        return response()->json(['data' => $results, 'meta' => ['dry_run' => $dryRun, 'summary' => $summary]]);
    }

    /** Ingestion status of a supplier by id. */
    public function show(Request $request, int $supplier): AgentSupplierResource
    {
        return new AgentSupplierResource($this->findAgentCompany($supplier, AgentContext::fromRequest($request)));
    }

    /** Look up a supplier this agent ingested by `?external_id=`. */
    public function lookup(Request $request): AgentSupplierResource
    {
        $request->validate(['external_id' => ['required', 'string', 'max:191']]);

        $company = Company::query()
            ->where('source', AgentContext::fromRequest($request)->source)
            ->where('external_id', $request->query('external_id'))
            ->first();

        if ($company === null) {
            throw new ApiException(404, 'not_found', 'No supplier with that external_id for this agent.');
        }

        return new AgentSupplierResource($company);
    }

    /** Upload the supplier logo (multipart `image`, jpg/png/webp, max 5 MB). Remote URLs are never fetched. */
    public function logo(Request $request, int $supplier): JsonResponse
    {
        $company = $this->findAgentCompany($supplier, AgentContext::fromRequest($request));

        if ($blocked = $this->ingestion->companyProductBlock($company)) {
            throw new ApiException($blocked['status'], $blocked['code'], $blocked['message'], ['existing_id' => $blocked['existing_id']]);
        }

        // The logo is live profile content: frozen once staff take the
        // supplier out of draft (same rule as profile updates).
        if ($company->status !== CompanyStatus::Draft) {
            throw new ApiException(409, 'supplier_locked', 'This supplier is in staff review or verified; its logo can no longer be changed by an agent.', ['existing_id' => $company->getKey()]);
        }

        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        $path = $request->file('image')->store('companies/logos', 'public');
        $company->forceFill(['logo_path' => $path, 'needs_review' => true])->save();

        $this->ingestion->logUpload($company, AgentContext::fromRequest($request), $path);

        return response()->json(['result' => 'updated', 'data' => new AgentSupplierResource($company->refresh())], 201);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function batchItem(int $index, array $item, AgentContext $ctx, bool $dryRun): array
    {
        $products = is_array($item['products'] ?? null) ? array_values($item['products']) : [];
        unset($item['products']);

        try {
            $outcome = $this->ingestion->upsertSupplier($item, $ctx, $dryRun);
        } catch (Throwable $e) {
            report($e);
            $outcome = ['result' => 'rejected', 'status' => 500, 'code' => 'server_error', 'message' => 'Unexpected error while processing this item.'];
        }

        $company = $outcome['company'] ?? null;
        $row = [
            'index' => $index,
            'external_id' => $item['external_id'] ?? null,
            'result' => $outcome['result'],
            'supplier_id' => $company?->getKey() ?? ($outcome['existing_id'] ?? null),
            'code' => $outcome['code'] ?? null,
            'message' => $outcome['message'] ?? null,
            'errors' => $outcome['errors'] ?? null,
            'products' => [],
        ];

        $canAttach = $outcome['result'] !== 'rejected' && ($company !== null || $dryRun);

        foreach ($products as $pIndex => $productInput) {
            if (! $canAttach) {
                $row['products'][] = ['index' => $pIndex, 'external_id' => $productInput['external_id'] ?? null, 'result' => 'rejected', 'code' => 'supplier_rejected', 'product_id' => null, 'errors' => null];

                continue;
            }

            try {
                $p = $this->ingestion->upsertProduct($company, (array) $productInput, $ctx, $dryRun);
            } catch (Throwable $e) {
                report($e);
                $p = ['result' => 'rejected', 'code' => 'server_error', 'message' => 'Unexpected error while processing this product.'];
            }

            $row['products'][] = [
                'index' => $pIndex,
                'external_id' => $productInput['external_id'] ?? null,
                'result' => $p['result'],
                'product_id' => ($p['product'] ?? null)?->getKey() ?? ($p['existing_id'] ?? null),
                'code' => $p['code'] ?? null,
                'errors' => $p['errors'] ?? null,
            ];
        }

        return $row;
    }

    /** Only suppliers ingested by the calling agent's own source are visible. */
    private function findAgentCompany(int $id, AgentContext $ctx): Company
    {
        $company = Company::query()->whereKey($id)->where('source', $ctx->source)->first();

        if ($company === null || ! AgentPrincipal::isAgentSource($company->source)) {
            throw new ApiException(404, 'not_found', 'No agent-sourced supplier with that id.');
        }

        return $company;
    }

    /** @param  array<string, mixed>  $outcome */
    private function respond(array $outcome): JsonResponse
    {
        if ($outcome['result'] === 'rejected') {
            if ($outcome['status'] === 422) {
                throw ValidationException::withMessages($outcome['errors']);
            }

            throw new ApiException($outcome['status'], $outcome['code'], $outcome['message'], isset($outcome['existing_id']) ? ['existing_id' => $outcome['existing_id']] : null);
        }

        $body = ['result' => $outcome['result']];
        if (isset($outcome['existing_id'])) {
            $body['existing_id'] = $outcome['existing_id'];
        }
        $body['data'] = new AgentSupplierResource($outcome['company']);

        return response()->json($body, $outcome['status']);
    }
}
