<?php

namespace App\Http\Controllers\Public;

use App\Domain\Compliance\Commands\OpenDisputeCommand;
use App\Enums\DisputeCategory;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Order;
use App\Services\DisputeService;
use App\Support\Bus\CommandBus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * The formal Dispute Resolution workflow (blueprint §64), reachable by
 * either party to an order — the buyer, or a member of the supplier company —
 * unlike the buyer-only `/account` area. Authorization is never taken from
 * the URL: DisputeService::isOrderParty() and Dispute::isParty() both
 * re-derive party membership from the order's own relationships.
 *
 * This is additive to the existing lightweight "dispute" contact-form
 * category in Public\ContactController, which stays as a general inbound
 * channel and is untouched here.
 */
class DisputeController extends Controller
{
    public function __construct(
        private readonly DisputeService $disputes,
        private readonly CommandBus $commandBus,
    ) {}

    public function index(Request $request, Order $order): View
    {
        $user = $request->user();

        abort_unless($this->disputes->isOrderParty($order, $user), 403);

        $order->load(['disputes.raisedByUser', 'disputes.raisedByCompany', 'disputes.respondentCompany']);

        return view('public.disputes.index', [
            'order' => $order,
            'disputes' => $order->disputes,
            'categories' => DisputeCategory::options(),
        ]);
    }

    public function store(Request $request, Order $order): RedirectResponse
    {
        abort_unless($this->disputes->isOrderParty($order, $request->user()), 403);

        $data = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', DisputeCategory::values())],
            'description' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $dispute = $this->commandBus->dispatch(new OpenDisputeCommand(
                orderId: $order->getKey(),
                actingUserId: $request->user()->getKey(),
                category: DisputeCategory::from($data['category']),
                description: $data['description'],
            ));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('disputes.show', ['order' => $order->getKey(), 'dispute' => $dispute->getKey()])
            ->with('status', 'Dispute opened.');
    }

    public function show(Request $request, Order $order, Dispute $dispute): View
    {
        $user = $request->user();

        abort_unless($dispute->order_id === $order->getKey(), 404);
        abort_unless($dispute->isParty($user), 403);

        $dispute->load(['evidence.submittedByUser', 'evidence.submittedByCompany', 'messages.user', 'messages.company', 'raisedByUser', 'respondentCompany']);

        return view('public.disputes.show', [
            'order' => $order,
            'dispute' => $dispute,
        ]);
    }

    public function submitEvidence(Request $request, Order $order, Dispute $dispute): RedirectResponse
    {
        abort_unless($dispute->order_id === $order->getKey(), 404);

        $data = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'max:'.(DisputeService::MAX_BYTES / 1024)],
        ]);

        try {
            $this->disputes->submitEvidence($dispute, $request->user(), $data['description'], $request->file('file'));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Evidence submitted.');
    }

    public function reply(Request $request, Order $order, Dispute $dispute): RedirectResponse
    {
        abort_unless($dispute->order_id === $order->getKey(), 404);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $this->disputes->reply($dispute, $request->user(), $data['body']);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Response sent.');
    }

    public function appeal(Request $request, Order $order, Dispute $dispute): RedirectResponse
    {
        abort_unless($dispute->order_id === $order->getKey(), 404);

        try {
            $dispute->appeal($request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Dispute appealed.');
    }
}
