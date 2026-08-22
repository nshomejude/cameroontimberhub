<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreRfqRequest;
use App\Http\Resources\Api\V1\QuoteResource;
use App\Http\Resources\Api\V1\RfqResource;
use App\Services\BuyerApiScope;
use App\Services\IntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Buyer RFQs over token auth.
 *
 * Creation goes through IntakeService::createRfq() — the single RFQ write path
 * — so reference generation, risk scoring, the `rfqs.user_id` owner backfill
 * and the signed 48-hour verification mail all behave exactly as they do on the
 * public wizard and in chat. The honeypot / minimum-form-time check runs first,
 * from the same service.
 *
 * The email-verification gate
 * ---------------------------
 * ENFORCED, and satisfied — never skipped — under exactly the condition
 * ChatCommerceService already established: the request is authenticated, the
 * account's own `users.email_verified_at` is set, and the RFQ's `buyer_email`
 * is that same verified address. The API takes `buyer_email` from the token
 * holder's account and refuses to read it from the body at all, so the
 * "different address" case cannot arise here.
 *
 * Note that `User` does not implement MustVerifyEmail and nothing in the
 * current registration flows sets `email_verified_at`. The practical
 * consequence is stated plainly rather than papered over: an API-registered
 * buyer's RFQ is created UNVERIFIED, and the emailed signed link is the only
 * way it reaches triage — identical to a guest submitting the public wizard.
 * The moment account email verification is switched on, this path starts
 * satisfying the gate with no further change. What it never does is mint a
 * verified RFQ merely because a bearer token was present.
 */
class RfqController extends Controller
{
    public function __construct(private readonly BuyerApiScope $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $rfqs = $this->scope->rfqs($request->user())
            ->with('items.species:id,slug,common_name')
            ->withCount(['quotes' => fn ($q) => $q->buyerVisible()])
            ->paginate(15);

        return RfqResource::collection($rfqs);
    }

    public function store(StoreRfqRequest $request, IntakeService $intake): JsonResponse
    {
        $buyer = $request->user();
        $data = $request->validated();

        // Silent neutral rejection, exactly as the web wizard does: a tripped
        // honeypot writes no row and reveals nothing about why.
        if ($intake->honeypotTripped($request->all() + ['buyer_email' => $buyer->email])) {
            return response()->json([
                'message' => 'Your request could not be submitted.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $rfq = $intake->createRfq(
            [
                'title' => $data['title'],
                'project_name' => $data['project_name'] ?? null,
                'buyer_name' => $buyer->name,
                'buyer_email' => strtolower(trim($buyer->email)),
                'buyer_country_code' => strtoupper((string) $data['buyer_country_code']),
                'destination_country_code' => strtoupper((string) $data['destination_country_code']),
                'incoterm' => $data['incoterm'] ?? null,
                'shipping_port' => $data['shipping_port'] ?? null,
                'target_amount' => $data['target_amount'] ?? null,
                'target_currency' => $data['target_currency'] ?? null,
                'deadline' => $data['deadline'] ?? null,
                'notes' => $data['notes'],
            ],
            array_map(fn (array $item): array => [
                'species_id' => $item['species_id'] ?? null,
                'species_text' => $item['species_text'] ?? null,
                'form' => $item['form'],
                'grade' => $item['grade'] ?? null,
                'dimensions' => $item['dimensions'] ?? null,
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'moisture_content' => $item['moisture_content'] ?? null,
            ], $data['items']),
            'api',
        );

        // See the class docblock: satisfied, never skipped.
        if ($buyer->email_verified_at !== null
            && strtolower(trim((string) $buyer->email)) === strtolower(trim((string) $rfq->buyer_email))) {
            $intake->verifyRfq($rfq);
        }

        $rfq->refresh()->load('items.species:id,slug,common_name');

        return (new RfqResource($rfq))
            ->additional(['meta' => [
                'email_verification_required' => $rfq->email_verified_at === null,
            ]])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, string $reference): RfqResource
    {
        $rfq = $this->scope->rfq($request->user(), $reference);
        $rfq->load('items.species:id,slug,common_name');
        $rfq->loadCount(['quotes' => fn ($q) => $q->buyerVisible()]);

        return new RfqResource($rfq);
    }

    /** Quotes suppliers have returned on one of the buyer's own RFQs. */
    public function quotes(Request $request, string $reference): AnonymousResourceCollection
    {
        $rfq = $this->scope->rfq($request->user(), $reference);

        $quotes = $rfq->quotes()
            ->buyerVisible()
            ->with(['items', 'company:id,slug,legal_name,trade_name,city,region,status,logo_path,verified_at,is_featured,years_experience,response_rate_percent,orders_completed,rating_avg,rating_count,supplier_type,country_code'])
            ->orderByDesc('submitted_at')
            ->get();

        return QuoteResource::collection($quotes);
    }
}
