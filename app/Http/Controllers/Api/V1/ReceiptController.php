<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ReceiptResource;
use App\Services\BuyerApiScope;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The buyer's own live (non-voided) receipts, over token auth — the API
 * counterpart of `/account/receipts` and of the printable
 * `orders/{order}/receipt` web view, reshaped for the mobile app.
 *
 * `{receiptNumber}` is the public, opaque `receipt_number` (matching how
 * `orders/{reference}`/`rfqs/{reference}` already use reference strings, not
 * ids). Both routes go through `BuyerApiScope::receipts()`/`receipt()`,
 * which already excludes voided receipts — a voided receipt's number 404s
 * here exactly like another buyer's, so neither confirms which numbers were
 * ever real. See that method's docblock for why "just don't show it any
 * more" is the right behaviour for a void, distinct from the public
 * `/verify` page (which deliberately still reports VOID by design, so a
 * holder of a paper copy can confirm it was revoked).
 *
 * Deliberately read-only and with no download/PDF endpoint: a receipt has
 * no file artifact distinct from its own data (see `ReceiptResource`'s
 * docblock) — unlike `OrderDocumentController`/`CompanyDocumentController`,
 * which stream real uploaded files, there is nothing here to stream.
 *
 * `Order::receipt` is NOT already nested inside `OrderResource` (that
 * resource is deliberately narrow — see its own docblock, "no receipt
 * verification token"), so there is no overlap/duplication with this
 * dedicated receipts listing to document beyond that absence.
 */
class ReceiptController extends Controller
{
    public function __construct(private readonly BuyerApiScope $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $receipts = $this->scope->receipts($request->user())
            ->with('order:id,reference_code,supplier_name,status')
            ->paginate(15);

        return ReceiptResource::collection($receipts);
    }

    public function show(Request $request, string $receiptNumber): ReceiptResource
    {
        $receipt = $this->scope->receipt($request->user(), $receiptNumber);
        $receipt->load('order:id,reference_code,supplier_name,status');

        return new ReceiptResource($receipt);
    }
}
