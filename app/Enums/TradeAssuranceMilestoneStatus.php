<?php

namespace App\Enums;

/**
 * Blueprint §28 "Trade Assurance Phase 1" milestone lifecycle.
 *
 * Coordination/tracking only -- NOT a fund-custody state machine. No status
 * here ever moves money; `Released` records that the parties consider the
 * milestone settled, nothing more. Real escrow requires a licensed financial
 * partner and is explicitly out of scope for Phase 1.
 */
enum TradeAssuranceMilestoneStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case BuyerConfirmed = 'buyer_confirmed';
    case Disputed = 'disputed';
    case Released = 'released';

    public function label(): string
    {
        return __('messages.enums.trade_assurance_milestone_status.'.$this->value);
    }
}
