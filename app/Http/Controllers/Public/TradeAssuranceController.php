<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TradeAssuranceMilestone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Buyer-facing Trade Assurance screen (blueprint §28, Phase 1).
 *
 * Coordination/tracking only — NOT escrow or fund custody. This lets the
 * buyer on an order see its agreed milestones and confirm one as met; no
 * money moves anywhere in this controller.
 *
 * Authorisation is never taken from the URL: `{order}` is resolved by route
 * binding, but every action re-checks server-side that the authenticated
 * user IS the order's buyer account (`order->user_id`) before doing
 * anything, exactly like TradeAssuranceMilestone::confirmByBuyer() does at
 * the model layer. A stranger — including another company's buyer — gets a
 * 404, never a 403 that would confirm the order exists.
 */
class TradeAssuranceController extends Controller
{
    public function show(Request $request, Order $order): View
    {
        $this->authoriseBuyer($request, $order);

        $agreement = $order->tradeAssuranceAgreement()->with('milestones')->first();

        return view('public.account.trade-assurance', [
            'order' => $order,
            'agreement' => $agreement,
        ]);
    }

    public function confirm(Request $request, Order $order, TradeAssuranceMilestone $milestone): RedirectResponse
    {
        $this->authoriseBuyer($request, $order);

        if ($milestone->loadMissing('agreement')->agreement?->order_id !== $order->getKey()) {
            abort(404);
        }

        try {
            $milestone->confirmByBuyer($request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Milestone confirmed.');
    }

    /** 404s (never 403) unless the signed-in user IS this order's buyer account. */
    private function authoriseBuyer(Request $request, Order $order): void
    {
        $user = $request->user();

        if (! $user || $order->user_id === null || (int) $order->user_id !== (int) $user->getKey()) {
            abort(404);
        }
    }
}
