<?php

namespace App\Http\Controllers\Public;

use App\Domain\Trade\Queries\ListBuyerOrdersQuery;
use App\Domain\Trade\Queries\ListBuyerQuotesQuery;
use App\Domain\Trade\Queries\ListBuyerReceiptsQuery;
use App\Domain\Trade\Queries\ListBuyerRfqsQuery;
use App\Http\Controllers\Controller;
use App\Services\BuyerDashboard;
use App\Services\BuyerRfqAccess;
use App\Support\Bus\QueryBus;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The buyer account area.
 *
 * Route prefix is `/account`, not `/dashboard`: `/dashboard` is the Filament
 * exporter panel for supplier companies, and `/admin` is the staff panel, so a
 * third meaning of "dashboard" would be ambiguous for users and a route
 * collision for the framework.
 *
 * Access: `auth` (guests are bounced to login with the intended URL preserved)
 * then `buyer` (EnsureBuyerAccount), which sends staff and company members to
 * their own panels. Every listing is scoped by BuyerDashboard, which resolves
 * ownership from `rfqs.user_id` and never from a request parameter — so there
 * is no id to enumerate. Deep links into the existing RFQ/quote/order/receipt
 * screens are built by BuyerRfqAccess, which for a signed-in owner emits a
 * plain (unsigned) URL, keeping shareable signatures out of the address bar.
 *
 * Every page is noindex: these are private records.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly BuyerDashboard $dashboard,
        private readonly BuyerRfqAccess $access,
        private readonly QueryBus $queryBus,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        if ($this->dashboard->isEmpty($user)) {
            return view('public.account.empty', [
                'user' => $user,
            ]);
        }

        return view('public.account.dashboard', [
            'user' => $user,
            'stats' => $this->dashboard->stats($user),
            'awardedValue' => $this->dashboard->awardedValueThisYear($user),
            'trend' => $this->dashboard->valueTrend($user),
            'byStatus' => $this->dashboard->ordersByStatus($user),
            'recentOrders' => $this->dashboard->recentOrders($user),
            'recentQuotes' => $this->dashboard->recentQuotes($user),
            'topSuppliers' => $this->dashboard->topSuppliers($user),
            'activity' => $this->dashboard->activity($user),
            'access' => $this->access,
        ]);
    }

    public function rfqs(Request $request): View
    {
        return view('public.account.rfqs', [
            'rfqs' => $this->queryBus->dispatch(new ListBuyerRfqsQuery($request->user()->getKey())),
            'access' => $this->access,
        ]);
    }

    public function quotes(Request $request): View
    {
        return view('public.account.quotes', [
            'quotes' => $this->queryBus->dispatch(new ListBuyerQuotesQuery($request->user()->getKey())),
            'access' => $this->access,
        ]);
    }

    public function orders(Request $request): View
    {
        return view('public.account.orders', [
            'orders' => $this->queryBus->dispatch(new ListBuyerOrdersQuery($request->user()->getKey())),
            'access' => $this->access,
        ]);
    }

    public function receipts(Request $request): View
    {
        return view('public.account.receipts', [
            'receipts' => $this->queryBus->dispatch(new ListBuyerReceiptsQuery($request->user()->getKey())),
            'access' => $this->access,
        ]);
    }
}
