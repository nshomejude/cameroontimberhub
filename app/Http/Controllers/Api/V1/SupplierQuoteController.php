<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSupplierQuoteRequest;
use App\Http\Resources\Api\V1\SupplierQuoteResource;
use App\Models\Company;
use App\Services\QuoteService;
use App\Services\SupplierApiScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Supplier quote submission over token auth — wraps the real domain write
 * path, `QuoteService`, exactly as the exporter panel's `CreateQuote` page
 * does (open a draft, attach line items, submit). There is no second write
 * path here: `QuoteService::open()`/`submit()` are the same methods the web
 * "Create Quote" form and its submit action call.
 *
 * Eligibility rules, taken from `QuoteService`/`QuoteForm::quotableRfqs()`,
 * not invented:
 *
 *  - The RFQ must be routed to this company (`assertQuotable()`) — otherwise
 *    a 404 here (via `SupplierApiScope::routedRfq()`, resolved before the
 *    service even runs), same enumeration-safety as the RFQ inbox.
 *  - The RFQ must be `RfqStatus::Approved` — `assertQuotable()`'s other half.
 *  - One ACTIVE quote per company per RFQ (`Quote::where(...)->where('status',
 *    '!=', 'withdrawn')->first()` in `QuoteService::open()`, backed by a
 *    partial unique DB index) — a second submission is a 409, not a 422: the
 *    request is well-formed, the RFQ exists and is quotable in general, it is
 *    just not available to THIS caller a second time (mirrors
 *    ReorderController's `already open` -> 409 mapping).
 *  - At least one line item — enforced both by `StoreSupplierQuoteRequest`
 *    (422 with a field key) and, redundantly, by `QuoteService::submit()`
 *    itself.
 */
class SupplierQuoteController extends Controller
{
    public function __construct(
        private readonly SupplierApiScope $scope,
        private readonly QuoteService $quotes,
    ) {}

    public function store(StoreSupplierQuoteRequest $request, string $reference): JsonResponse
    {
        $user = $request->user();
        $rfq = $this->scope->routedRfq($user, $reference);

        /** @var Company $company */
        $company = $this->scope->company($user);

        $data = $request->validated();

        try {
            $quote = $this->quotes->open($rfq, $company, [
                'currency' => $data['currency'] ?? $rfq->target_currency ?: 'USD',
                'incoterm' => $data['incoterm'] ?? $rfq->incoterm?->value,
                'shipping_amount' => $data['shipping_amount'] ?? null,
                'tax_amount' => $data['tax_amount'] ?? null,
                'lead_time_days' => $data['lead_time_days'] ?? null,
                'validity_days' => $data['validity_days'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (RuntimeException $e) {
            // "not routed" / "not approved" cannot actually happen here —
            // routedRfq() already 404s an unrouted RFQ, and an unapproved one
            // still resolves (routing exists regardless of RFQ status) so
            // this guard is real: it is where a not-approved RFQ surfaces.
            // "already has a quote" is the genuine double-submission case.
            if (str_contains($e->getMessage(), 'already has a quote')) {
                throw new ConflictException($e->getMessage(), 'quote_already_submitted', $e);
            }

            throw ValidationException::withMessages(['rfq' => $e->getMessage()]);
        }

        foreach ($data['items'] as $item) {
            $quote->items()->create([
                'description' => $item['description'],
                'species_id' => $item['species_id'] ?? null,
                'form' => $item['form'] ?? null,
                'grade' => $item['grade'] ?? null,
                'dimensions' => $item['dimensions'] ?? null,
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'unit_price' => $item['unit_price'],
                'line_total' => 0,
                'notes' => $item['notes'] ?? null,
            ]);
        }

        try {
            $quote = $this->quotes->submit($quote->fresh(), $user);
        } catch (RuntimeException $e) {
            throw new ConflictException($e->getMessage(), 'quote_not_submittable', $e);
        }

        $quote->load(['items', 'company', 'rfq']);

        return response()->json([
            'data' => new SupplierQuoteResource($quote),
        ], 201);
    }

    /** The caller's own submitted-across-all-RFQs quotes, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $quotes = $this->scope->quotes($request->user())
            ->with(['items', 'rfq'])
            ->paginate(15);

        return SupplierQuoteResource::collection($quotes);
    }

    /** One of the caller's own submitted quotes by reference, or 404. */
    public function show(Request $request, string $reference): SupplierQuoteResource
    {
        $quote = $this->scope->quotes($request->user())
            ->where('reference_code', $reference)
            ->with(['items', 'rfq', 'supersedes'])
            ->firstOrFail();

        return new SupplierQuoteResource($quote);
    }

    /**
     * Withdraw one of the caller's own quotes. Wraps `QuoteService::withdraw()`
     * exactly — the same transition the exporter panel's quote actions use.
     * An illegal transition (already withdrawn/decided/expired — see
     * `QuoteService::TRANSITIONS`) is a 409, not a 422: the request is
     * well-formed, the quote just is not in a withdrawable state anymore.
     */
    public function withdraw(Request $request, string $reference, QuoteService $quotes): JsonResponse
    {
        $quote = $this->scope->quotes($request->user())
            ->where('reference_code', $reference)
            ->with(['items', 'rfq', 'supersedes'])
            ->firstOrFail();

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $quote = $quotes->withdraw($quote, $request->user(), $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            throw new ConflictException($e->getMessage(), 'quote_not_withdrawable', $e);
        }

        return response()->json([
            'data' => new SupplierQuoteResource($quote),
        ]);
    }
}
