<?php

namespace App\Http\Controllers\Public;

use App\Enums\ConversationTopic;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Services\MessagingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The buyer side of messaging, under `/account`.
 *
 * The pages render server-side (the Livewire components' first paint is real
 * HTML, so a thread is readable with JavaScript disabled) and Livewire only
 * adds polling and in-place posting on top. `store()` exists so the composer
 * degrades to a plain form POST when Livewire is not running.
 *
 * No id from the request is ever trusted: MessagingService::authorize() is the
 * single gate and answers 404 for a non-participant so ids cannot be probed.
 * The account layout is noindex, so every one of these screens is too.
 */
class MessageController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    public function index(): View
    {
        return view('public.account.messages.index', [
            'conversation' => null,
        ]);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $this->messaging->authorize($request->user(), $conversation);

        return view('public.account.messages.index', [
            'conversation' => $conversation,
        ]);
    }

    public function create(): View
    {
        return view('public.account.messages.create');
    }

    /**
     * "Message Supplier" — the in-platform counterpart to the WhatsApp/phone
     * link on a supplier profile. Find-or-create, then land on the thread.
     */
    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company' => ['required', 'string', 'exists:companies,slug'],
            'topic' => ['nullable', 'string', 'in:'.implode(',', ConversationTopic::values())],
            'product' => ['nullable', 'string', 'exists:products,slug'],
            'order' => ['nullable', 'integer'],
        ]);

        $company = Company::where('slug', $data['company'])->firstOrFail();

        // Same gate as the API's POST /conversations: a company buyers cannot
        // see (suspended, archived, pending, incomplete) cannot be messaged.
        if (! $company->isPubliclyVisible()) {
            return back()->withErrors(['company' => __('messages.account_center.company_unavailable')]);
        }
        $product = isset($data['product']) ? Product::where('slug', $data['product'])->first() : null;

        // An order may only be attached when this buyer actually owns it.
        $order = isset($data['order'])
            ? Order::where('user_id', $request->user()->getKey())->whereKey($data['order'])->first()
            : null;

        // Starting a NEW conversation needs a verified email address (spam /
        // impersonation guard). Re-opening an existing thread does not.
        if (! $request->user()->hasVerifiedEmail()) {
            $existing = Conversation::query()
                ->where('user_id', $request->user()->getKey())
                ->where('company_id', $company->getKey())
                ->where('order_id', $order?->getKey())
                ->where('status', '!=', \App\Enums\ConversationStatus::Closed->value)
                ->orderByDesc('id')
                ->first();

            if ($existing === null) {
                return redirect()->route('verification.notice')->with(
                    'status',
                    __('Please verify your email address before messaging suppliers. Check your inbox for the link, or resend it below.'),
                );
            }

            return redirect()->route('account.messages.show', $existing);
        }

        $conversation = $this->messaging->start(
            buyer: $request->user(),
            company: $company,
            topic: ConversationTopic::tryFrom($data['topic'] ?? '') ?? ($product ? ConversationTopic::Product : ConversationTopic::General),
            product: $product,
            order: $order,
        );

        return redirect()->route('account.messages.show', $conversation);
    }

    /** No-JavaScript fallback for the composer. */
    public function store(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->messaging->authorize($request->user(), $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $this->messaging->post($conversation, $request->user(), $data['body']);

        return redirect()->route('account.messages.show', $conversation);
    }
}
