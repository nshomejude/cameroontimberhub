<?php

namespace App\Models;

use App\Enums\TradeAssuranceMilestoneStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A single tracked step within a TradeAssuranceAgreement (blueprint §28).
 *
 * `amount_share_percent` is informational only -- a display figure the
 * parties agree represents this milestone's share of the order value. It is
 * never read by, or wired to, any payment gateway or fund-movement code.
 */
class TradeAssuranceMilestone extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => TradeAssuranceMilestoneStatus::class,
            'sequence' => 'integer',
            'expected_completion_date' => 'date',
            'amount_share_percent' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(TradeAssuranceAgreement::class, 'trade_assurance_agreement_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * Buyer confirms this milestone was met.
     *
     * The buyer on an order is its `user_id` account -- orders have no
     * buyer-company FK (buyer_company is a frozen name snapshot; a guest
     * buyer with no account is also possible). This throws unless the given
     * user IS that order's account holder, never trusting anything the
     * caller passed beyond the authenticated user itself.
     */
    public function confirmByBuyer(User $user): self
    {
        // Milestones are usually reached via $agreement->milestones, which
        // leaves the inverse `agreement` relation unloaded — resolve it here
        // rather than lazy-loading (N+1 guard is on in dev/CI).
        $order = $this->loadMissing('agreement.order')->agreement?->order;

        if (! $order || $order->user_id === null || (int) $order->user_id !== (int) $user->getKey()) {
            throw new RuntimeException('Only the buyer on this order may confirm this milestone.');
        }

        $this->forceFill([
            'status' => TradeAssuranceMilestoneStatus::BuyerConfirmed,
            'confirmed_by' => $user->getKey(),
            'confirmed_at' => now(),
        ])->save();

        return $this;
    }

    public function markInProgress(): self
    {
        $this->forceFill(['status' => TradeAssuranceMilestoneStatus::InProgress])->save();

        return $this;
    }
}
