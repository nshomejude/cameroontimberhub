<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CommissionPaymentSetting;
use App\Models\CommissionStatement;
use App\Models\CommissionStatementLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A monthly marketplace-commission statement of the caller's company
 * (supplier API, `/api/v1/supplier/commission/statements`). Money is a 2dp
 * decimal string in the statement currency; the `*_formatted` twins use the
 * currency's real precision (XAF = 0dp).
 *
 * Lines, deposits and the platform payment instructions are included only
 * when the controller loaded them (the detail endpoint).
 *
 * @mixin CommissionStatement
 */
class CommissionStatementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommissionStatement $s */
        $s = $this->resource;

        return [
            'number' => $s->statement_number,
            'period' => $s->period_start->format('Y-m'),
            'period_label' => $s->periodLabel(),
            'period_start' => $s->period_start->toDateString(),
            'period_end' => $s->period_end->toDateString(),
            'currency' => $s->currency->value,
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'charges_amount' => (string) $s->charges_amount,
            'adjustments_amount' => (string) $s->adjustments_amount,
            'total_amount' => (string) $s->total_amount,
            'total_formatted' => $s->money(),
            'amount_paid' => (string) $s->amount_paid,
            'amount_due' => $s->outstanding(),
            'amount_due_formatted' => $s->money($s->outstanding()),
            'is_overdue' => $s->isPastDue(),
            'issued_at' => $s->issued_at?->toIso8601String(),
            'due_date' => $s->due_date->toDateString(),
            'paid_at' => $s->paid_at?->toIso8601String(),
            'voided_at' => $s->voided_at?->toIso8601String(),
            'void_reason' => $s->void_reason,
            'can_report_deposit' => $s->isOpen(),
            'lines' => $this->whenLoaded('lines', fn () => $s->lines->map(fn (CommissionStatementLine $l) => [
                'kind' => $l->kind,
                'order_reference' => $l->order_reference,
                'description' => $l->description,
                'charged_at' => $l->charged_at?->toIso8601String(),
                'order_subtotal' => (string) $l->order_subtotal,
                'commission_rate' => $l->commission_rate !== null ? (string) $l->commission_rate : null,
                'commission_amount' => (string) $l->commission_amount,
                'credited_amount' => (string) $l->credited_amount,
                'amount' => (string) $l->amount,
            ])->values()),
            'deposits' => $this->whenLoaded('deposits', fn () => CommissionDepositResource::collection($s->deposits)),
            'payment_instructions' => $this->when($s->relationLoaded('lines'), fn () => self::paymentInstructions()),
        ];
    }

    /** @return array{reference_hint: string, methods: list<array{method: string, label: string, details: array<string, string>}>, notes: ?string} */
    public static function paymentInstructions(): array
    {
        $settings = CommissionPaymentSetting::current();

        return [
            'reference_hint' => 'Quote your statement number as the payment reason / reference.',
            'methods' => $settings->instructions(),
            'notes' => $settings->extra_instructions,
        ];
    }
}
