<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CommissionDeposit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A commission deposit the caller's company reported (or finance recorded)
 * against one of its statements. The proof file itself is never exposed by
 * URL — `has_proof` only says one was attached.
 *
 * @mixin CommissionDeposit
 */
class CommissionDepositResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommissionDeposit $d */
        $d = $this->resource;

        return [
            'id' => $d->id,
            'method' => $d->method->value,
            'method_label' => $d->method->label(),
            'source' => $d->source,
            'status' => $d->status->value,
            'status_label' => $d->status->label(),
            'currency' => $d->currency->value,
            'amount' => (string) $d->amount,
            'amount_received' => $d->amount_received !== null ? (string) $d->amount_received : null,
            'transaction_reference' => $d->transaction_reference,
            'paid_on' => $d->paid_on?->toDateString(),
            'has_proof' => $d->hasProof(),
            'rejection_reason' => $d->rejection_reason,
            'reviewed_at' => $d->reviewed_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
