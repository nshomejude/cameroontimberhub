<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\ProductStatus;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Agent\AgentProductResource;
use App\Models\Company;
use App\Models\Product;
use App\Services\Agent\AgentContext;
use App\Services\Agent\AgentIngestionService;
use App\Support\Agent\AgentPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Agent Ingestion Gateway — product endpoints. Products are always created
 * `draft` with `needs_review = true`, and only on agent-sourced, unclaimed
 * suppliers (409 `supplier_owned` otherwise).
 *
 * @tags Agent ingestion
 */
class AgentProductController extends Controller
{
    public function __construct(private readonly AgentIngestionService $ingestion) {}

    /** Upsert a product on an agent-sourced supplier by `external_id`. 201 created / 200 updated. */
    public function store(Request $request, int $supplier): JsonResponse
    {
        $company = Company::query()->whereKey($supplier)->first();

        if ($company === null) {
            throw new ApiException(404, 'not_found', 'Supplier not found.');
        }

        $outcome = $this->ingestion->upsertProduct($company, $request->all(), AgentContext::fromRequest($request));

        if ($outcome['result'] === 'rejected') {
            if ($outcome['status'] === 422) {
                throw ValidationException::withMessages($outcome['errors']);
            }

            throw new ApiException($outcome['status'], $outcome['code'], $outcome['message'], isset($outcome['existing_id']) ? ['existing_id' => $outcome['existing_id']] : null);
        }

        return response()->json([
            'result' => $outcome['result'],
            'data' => new AgentProductResource($outcome['product']),
        ], $outcome['status']);
    }

    /** Upload a product's primary image (multipart `image`, jpg/png/webp, max 5 MB). Remote URLs are never fetched. */
    public function image(Request $request, int $product): JsonResponse
    {
        $ctx = AgentContext::fromRequest($request);
        $record = Product::query()->whereKey($product)->where('source', $ctx->source)->first();

        if ($record === null || ! AgentPrincipal::isAgentSource($record->source)) {
            throw new ApiException(404, 'not_found', 'No agent-sourced product with that id.');
        }

        if ($blocked = $this->ingestion->companyProductBlock($record->company)) {
            throw new ApiException($blocked['status'], $blocked['code'], $blocked['message'], ['existing_id' => $blocked['existing_id']]);
        }

        if ($record->status === ProductStatus::Active) {
            throw new ApiException(409, 'product_locked', 'This product has been published by staff and can no longer be changed by an agent.', ['existing_id' => $record->getKey()]);
        }

        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        $path = $request->file('image')->store('products', 'public');
        $record->forceFill(['primary_image_path' => $path, 'needs_review' => true])->save();

        $this->ingestion->logUpload($record, $ctx, $path);

        return response()->json(['result' => 'updated', 'data' => new AgentProductResource($record->refresh())], 201);
    }
}
