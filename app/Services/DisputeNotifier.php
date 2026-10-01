<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\User;
use App\Notifications\DisputeAppealedStaffNotification;
use App\Notifications\DisputeOpenedNotification;
use App\Notifications\DisputeOpenedStaffNotification;
use App\Notifications\DisputeReplyNotification;
use App\Notifications\DisputeResolvedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Single place that decides WHO hears about a dispute event, shared by the
 * web (Public\DisputeController), API (Api\V1\DisputeController) and admin
 * (DisputesTable) entry points so they cannot drift apart.
 *
 * Parties are re-derived from the order, like Dispute::isParty(): the buyer
 * (order.user_id) and every member of the supplier company (order.company_id).
 */
class DisputeNotifier
{
    /** @return Collection<int, User> */
    public function parties(Dispute $dispute): Collection
    {
        $order = $dispute->order;
        if ($order === null) {
            return collect();
        }

        $users = collect();
        if ($order->user_id !== null && ($buyer = User::find($order->user_id))) {
            $users->push($buyer);
        }
        if ($order->company_id !== null) {
            $users = $users->merge(User::query()
                ->whereHas('companies', fn ($q) => $q->whereKey($order->company_id))
                ->get());
        }

        return $users->unique(fn (User $u) => $u->getKey())->values();
    }

    /** @return Collection<int, User> */
    public function otherParties(Dispute $dispute, User $actor): Collection
    {
        return $this->parties($dispute)->reject(fn (User $u) => (int) $u->getKey() === (int) $actor->getKey())->values();
    }

    /** A dispute was opened: alert the other party and the dispute desk. */
    public function opened(Dispute $dispute, User $actor): void
    {
        Notification::send($this->otherParties($dispute, $actor), new DisputeOpenedNotification($dispute));

        $staff = User::permission('disputes.manage')->get();
        Notification::send($staff, new DisputeOpenedStaffNotification($dispute));
    }

    /** A party appealed a resolved dispute: alert the dispute desk. */
    public function appealed(Dispute $dispute): void
    {
        $staff = User::permission('disputes.manage')->get();
        Notification::send($staff, new DisputeAppealedStaffNotification($dispute));
    }

    /** A party replied: alert the other party. */
    public function replied(Dispute $dispute, User $actor, string $body): void
    {
        Notification::send($this->otherParties($dispute, $actor), new DisputeReplyNotification($dispute, $body));
    }

    /** Staff resolved (or, after an appeal, re-decided) or closed the dispute: alert both parties. */
    public function decided(Dispute $dispute, string $event): void
    {
        Notification::send($this->parties($dispute), new DisputeResolvedNotification($dispute, $event));
    }
}
