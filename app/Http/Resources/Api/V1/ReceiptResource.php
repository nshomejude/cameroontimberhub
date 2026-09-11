<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Receipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A buyer's own receipt — the API counterpart of `/account/receipts` and the
 * printable `orders/{order}/receipt` web view, reshaped for the mobile app.
 *
 * A receipt is data, not a file: the web "print view" is just this same
 * data rendered into a Blade template with `window.print()`, so there is no
 * separate PDF artifact to stream. This resource is deliberately the whole
 * payload a mobile client needs to render its own receipt screen — no
 * dedicated download/PDF endpoint exists or is needed (see
 * `ReceiptController`'s docblock).
 *
 * `order` is a minimal reference, not the full `OrderResource` — a receipts
 * list has no reason to eager-load/serialize items, trade assurance, etc.
 * for every row; a client that needs the full order detail already has
 * `GET /orders/{reference}` for that (also true of the single-receipt
 * `show()` — kept identical to the list shape for one predictable contract).
 *
 * `amount` is the raw decimal string (Eloquent's `decimal:2` cast), not
 * `Receipt::money()`'s "USD 1,245.00" display string — a mobile client
 * formats its own currency display from `amount` + `currency` exactly like
 * `OrderResource` already does for order totals; baking in server-side
 * formatting here would be the only money field across `/api/v1` that
 * isn't a raw decimal.
 *
 * @mixin Receipt
 */
class ReceiptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'receipt_number' => $this->receipt_number,
            'amount' => (string) $this->amount,
            'currency' => $this->currency?->value,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'verification_status' => $this->verificationStatus(),
            'verification_url' => $this->verificationUrl(),
            'order' => $this->whenLoaded('order', fn () => [
                'reference' => $this->order->reference_code,
                'supplier_name' => $this->order->supplier_name,
                'status' => $this->order->status?->value,
            ]),
        ];
    }
}
