<?php

namespace App\Filament\Exporter\Pages;

use App\Enums\QuoteStatus;
use App\Enums\RfqStatus;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\TradeAssuranceMilestone;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Curated buyer landing dashboard (implementation blueprint §84): the small
 * set of things a buyer account cares about most across a procurement
 * cycle -- their own open RFQs, quotes awaiting a decision, active orders,
 * and (where applicable) trade-assurance milestones awaiting their
 * confirmation.
 *
 * Buyer identity in this codebase is the authenticated account itself
 * (Rfq::user_id / Order::user_id), not a Company -- a buyer need not belong
 * to a registered company (see Rfq::user() docblock: "the guest path...
 * relies on the signed link"). Every query below is scoped to
 * auth()->id() so one buyer never sees another buyer's procurement data.
 */
class BuyerProcurementWorkspace extends Page
{

    public static function getNavigationLabel(): string
    {
        return __('messages.filament.pages.my_procurement_nav');
    }

    public function getTitle(): string
    {
        return __('messages.filament.pages.procurement_workspace_title');
    }
    protected string $view = 'filament.exporter.pages.buyer-procurement-workspace';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;



    protected static ?int $navigationSort = 1;

    /** Open RFQs the logged-in buyer has raised, most recent first. */
    public function getOpenRfqs(): Collection
    {
        $userId = auth()->id();

        if (! $userId) {
            return collect();
        }

        return Rfq::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', [RfqStatus::Closed->value, RfqStatus::Rejected->value, RfqStatus::Spam->value])
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /** Submitted/viewed quotes on the buyer's own RFQs, awaiting accept/decline. */
    public function getPendingQuotes(): Collection
    {
        $userId = auth()->id();

        if (! $userId) {
            return collect();
        }

        return Quote::query()
            ->whereIn('status', [QuoteStatus::Submitted->value, QuoteStatus::Viewed->value])
            ->whereHas('rfq', fn ($q) => $q->where('user_id', $userId))
            ->with(['rfq', 'company'])
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /** The buyer's own orders that have not reached a terminal state. */
    public function getActiveOrders(): Collection
    {
        $userId = auth()->id();

        if (! $userId) {
            return collect();
        }

        return Order::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->with('company')
            ->latest('id')
            ->limit(10)
            ->get();
    }

    /**
     * Trade-assurance milestones awaiting the buyer's own confirmation, on
     * the buyer's own orders only. TradeAssuranceAgreement/Milestone already
     * exist in this codebase (blueprint §28) so no defensive guard is
     * needed here.
     */
    public function getMilestonesAwaitingConfirmation(): Collection
    {
        $userId = auth()->id();

        if (! $userId) {
            return collect();
        }

        return TradeAssuranceMilestone::query()
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereHas('agreement.order', fn ($q) => $q->where('user_id', $userId))
            ->with('agreement.order')
            ->orderBy('sequence')
            ->limit(10)
            ->get();
    }
}
