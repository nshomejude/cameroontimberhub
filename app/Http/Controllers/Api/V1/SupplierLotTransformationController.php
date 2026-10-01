<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LotTransformationResource;
use App\Models\LotTransformation;
use App\Models\User;
use App\Services\TransformationRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only mass-balance ledger for the caller's company as PROCESSOR —
 * API counterpart of the exporter
 * {@see \App\Filament\Exporter\Resources\LotTransformations\LotTransformationResource}.
 * Scoped to `processor_company_id` = the company resolved by
 * {@see TransformationRequestService::company()} (the flow that writes these
 * rows); another company's id 404s.
 */
class SupplierLotTransformationController extends Controller
{
    public function __construct(private readonly TransformationRequestService $requests) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return LotTransformationResource::collection(
            $this->query($request->user())->orderByDesc('processed_at')->orderByDesc('id')->paginate(15),
        );
    }

    public function show(Request $request, int|string $transformation): LotTransformationResource
    {
        $record = $this->query($request->user())
            ->whereKey((int) $transformation)
            ->with(['inputLots', 'outputLots'])
            ->firstOrFail();

        return new LotTransformationResource($record);
    }

    private function query(User $user): Builder
    {
        $companyId = $this->requests->company($user)?->getKey();

        return LotTransformation::query()->when(
            $companyId !== null,
            fn (Builder $q) => $q->where('processor_company_id', $companyId),
            fn (Builder $q) => $q->whereRaw('1 = 0'),
        );
    }
}
