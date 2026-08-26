<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreRfqRequest;
use App\Http\Resources\Api\V1\QuoteResource;
use App\Http\Resources\Api\V1\RfqResource;
use App\Models\Company;
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
 *
 * Which leaves the client with an RFQ it cannot release on its own, so
 * `resendVerification()` below re-sends the signed link on demand. That is an
 * interim path, not the fix. The real unblock is one of two operational
 * changes, neither of which belongs in this controller:
 *
 *   1. Switch on account email verification — make `User` implement
 *      MustVerifyEmail and have registration stamp `email_verified_at`. The
 *      condition in `store()` then starts satisfying the gate by itself.
 *   2. Configure real SMTP. Production currently runs `MAIL_MAILER=log`, so
 *      the signed link is written to a log file and never delivered; resending
 *      it changes nothing until a transport exists.
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
                // Descriptive, not identity: the organisation the buyer is
                // purchasing for. The web wizard collects it on the contact
                // step and stores it in `rfqs.buyer_company`; the API accepts
                // it on the same terms rather than dropping it silently.
                'buyer_company' => $data['buyer_company'] ?? null,
            ],
            // Line items arrive with `species_slug` already resolved to a
            // published `species_id` by StoreRfqRequest — an unresolvable slug
            // never reaches here, it 422s with a field key.
            $request->itemsForIntake(),
            'api',
        );

        // See the class docblock: satisfied, never skipped. When it is NOT
        // satisfied the RFQ stays unverified and the client's only lever is
        // POST /rfqs/{reference}/resend-verification — see the docblock for
        // why that is an interim path and what the real unblock is.
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

    /**
     * Re-send the signed 48-hour verification link for one of the buyer's own
     * RFQs.
     *
     * Scoped through BuyerApiScope, so another buyer's reference 404s rather
     * than 403s — the endpoint leaks no more than `show()` does. An RFQ that is
     * already verified is a no-op 200, not an error: the client asking twice is
     * a retry, not a fault, and answering 409 would only teach it to guess.
     *
     * This mints a fresh link through the same IntakeService helper
     * `createRfq()` uses; it does not verify anything itself.
     */
    public function resendVerification(Request $request, string $reference, IntakeService $intake): JsonResponse
    {
        $rfq = $this->scope->rfq($request->user(), $reference);

        $alreadyVerified = $rfq->email_verified_at !== null;

        if (! $alreadyVerified) {
            $intake->sendRfqVerificationMail($rfq);
        }

        return response()->json([
            'message' => $alreadyVerified
                ? 'This request is already verified.'
                : 'We have re-sent the confirmation email.',
            'data' => [
                'reference' => $rfq->reference_code,
                'email_verified' => $alreadyVerified,
                'email_verification_required' => ! $alreadyVerified,
                'sent' => ! $alreadyVerified,
            ],
        ]);
    }

    /** Quotes suppliers have returned on one of the buyer's own RFQs. */
    public function quotes(Request $request, string $reference): AnonymousResourceCollection
    {
        $rfq = $this->scope->rfq($request->user(), $reference);

        $quotes = $rfq->quotes()
            ->buyerVisible()
            ->with(['items', Company::cardEagerLoad()])
            ->orderByDesc('submitted_at')
            ->get();

        return QuoteResource::collection($quotes);
    }
}
