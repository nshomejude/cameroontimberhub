<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TransformationRequestResource;
use App\Services\TransformationRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The request/accept/quote/job pipeline for the Transformation Network — a
 * DIFFERENT controller from the read-only Api\V1\TransformationNetworkController
 * (directory + species match), per the brief. Thin: every rule lives in
 * TransformationRequestService, this only validates input and shapes the
 * envelope, mirroring ChatOrderController / SupplierRfqController.
 */
class TransformationRequestController extends Controller
{
    public function __construct(private readonly TransformationRequestService $requests) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $box = (string) $request->query('box', 'sent');

        $query = $box === 'received'
            ? $this->requests->received($request->user())
            : $this->requests->sent($request->user());

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        return TransformationRequestResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider_slug' => ['required', 'string', 'exists:companies,slug'],
            'service' => ['required', 'in:sawing,drying,planing,moulding,veneer,other'],
            'species_slug' => ['nullable', 'string', 'exists:species,slug'],
            'volume_m3' => ['required', 'numeric', 'min:0.01'],
            'input_description' => ['nullable', 'string'],
            'target_spec' => ['nullable', 'string'],
            'deadline' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! empty($data['species_slug'])) {
            $data['species_id'] = \App\Models\Species::query()->where('slug', $data['species_slug'])->value('id');
        }

        $created = $this->requests->create($request->user(), $data);

        return (new TransformationRequestResource($created))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, string $reference): TransformationRequestResource
    {
        return new TransformationRequestResource($this->requests->forParticipant($request->user(), $reference));
    }

    /* --------------------------------------------------------- provider side */

    public function accept(Request $request, string $reference): TransformationRequestResource
    {
        $tr = $this->requests->forParticipant($request->user(), $reference);

        return new TransformationRequestResource($this->requests->accept($request->user(), $tr));
    }

    public function decline(Request $request, string $reference): TransformationRequestResource
    {
        $data = $request->validate(['reason' => ['required', 'string']]);
        $tr = $this->requests->forParticipant($request->user(), $reference);

        return new TransformationRequestResource($this->requests->decline($request->user(), $tr, $data['reason']));
    }

    public function quote(Request $request, string $reference): TransformationRequestResource
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);
        $tr = $this->requests->forParticipant($request->user(), $reference);

        return new TransformationRequestResource($this->requests->quote($request->user(), $tr, $data));
    }

    public function startJob(Request $request, string $reference): TransformationRequestResource
    {
        $tr = $this->requests->forParticipant($request->user(), $reference);

        return new TransformationRequestResource($this->requests->startJob($request->user(), $tr));
    }

    public function completeJob(Request $request, string $reference): TransformationRequestResource
    {
        $data = $request->validate([
            'input_volume_m3' => ['nullable', 'numeric', 'min:0.01'],
            'output_volume_m3' => ['nullable', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
        ]);
        $tr = $this->requests->forParticipant($request->user(), $reference);

        $completed = $this->requests->completeJob(
            $request->user(),
            $tr,
            isset($data['input_volume_m3']) ? (float) $data['input_volume_m3'] : null,
            isset($data['output_volume_m3']) ? (float) $data['output_volume_m3'] : null,
            $data['notes'] ?? null,
        );

        return new TransformationRequestResource($completed);
    }

    /* ------------------------------------------------------- requester side */

    public function acceptQuote(Request $request, string $reference): TransformationRequestResource
    {
        $tr = $this->requests->forParticipant($request->user(), $reference);

        return new TransformationRequestResource($this->requests->acceptQuote($request->user(), $tr));
    }

    public function declineQuote(Request $request, string $reference): TransformationRequestResource
    {
        $tr = $this->requests->forParticipant($request->user(), $reference);

        return new TransformationRequestResource($this->requests->declineQuote($request->user(), $tr));
    }

    public function cancel(Request $request, string $reference): TransformationRequestResource
    {
        $tr = $this->requests->forParticipant($request->user(), $reference);

        return new TransformationRequestResource($this->requests->cancel($request->user(), $tr));
    }
}
