<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Blueprint §28 "Trade Assurance Phase 1": one agreement per Order, holding a
 * structured list of milestones the buyer and supplier track together.
 *
 * Coordination/tracking only -- explicitly NOT escrow or fund custody. No
 * money moves through this model or its milestones; real fund custody would
 * require a licensed financial partner and is out of scope for Phase 1.
 */
class TradeAssuranceAgreement extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /* --------------------------------------------------------- relations */

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(TradeAssuranceMilestone::class)->orderBy('sequence');
    }

    /* ----------------------------------------------------------- helpers */

    /**
     * Seeds the default milestone set for a freshly-awarded order:
     * Order Confirmed, Goods Dispatched, Goods Delivered, Buyer Confirmation.
     *
     * Idempotent-ish in intent but not enforced here -- callers (e.g. the
     * order creation flow) are responsible for calling this once per order.
     */
    public static function createDefaultMilestones(Order $order, ?User $createdBy = null): self
    {
        $agreement = self::create([
            'order_id' => $order->getKey(),
            'created_by' => $createdBy?->getKey(),
        ]);

        $defaults = [
            ['title' => 'Order Confirmed', 'sequence' => 1],
            ['title' => 'Goods Dispatched', 'sequence' => 2],
            ['title' => 'Goods Delivered', 'sequence' => 3],
            ['title' => 'Buyer Confirmation', 'sequence' => 4],
        ];

        foreach ($defaults as $milestone) {
            $agreement->milestones()->create([
                'title' => $milestone['title'],
                'sequence' => $milestone['sequence'],
                'status' => \App\Enums\TradeAssuranceMilestoneStatus::Pending->value,
            ]);
        }

        return $agreement;
    }
}
