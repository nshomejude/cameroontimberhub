<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Services\ReceiptVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public receipt-verification page.
 *
 * Anyone may use it — that is the point of a verification service — so it is
 * treated as a security boundary: rate-limited in routes/web.php, and every
 * fact it renders comes from ReceiptVerifier::publicPayload(), which is a
 * hand-written allow-list. A failed lookup returns the same generic "not found"
 * regardless of why, so the page cannot be used as an oracle.
 */
class ReceiptVerificationController extends Controller
{
    public function __construct(private readonly ReceiptVerifier $verifier) {}

    /** The form, plus any result handed over by the token route. */
    public function create(Request $request): View
    {
        $receipt = ($id = $request->session()->get('verified_receipt_id'))
            ? Receipt::with('order.company')->find($id)
            : null;

        return view('public.receipts.verify', [
            'reference' => $receipt?->receipt_number ?? '',
            // Already counted by token(); rendering the result is not a new check.
            'result' => $receipt ? $this->verifier->publicPayload($receipt) : null,
            'searched' => $receipt !== null || $request->session()->get('verified_missing', false),
        ]);
    }

    /** Lookup by typed receipt number (or a pasted token). */
    public function store(Request $request): View
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
        ], [], ['reference' => 'receipt number']);

        $receipt = $this->verifier->find($data['reference']);

        return view('public.receipts.verify', [
            // Echo back only what the visitor typed if it was a receipt number;
            // never re-render a token into the form field.
            'reference' => $receipt?->receipt_number ?? $data['reference'],
            'result' => $receipt ? $this->verifier->publicPayload($this->verifier->recordCheck($receipt)) : null,
            'searched' => true,
        ]);
    }

    /**
     * Direct token link — what the printed verification URL points at.
     *
     * It redirects rather than rendering: the token is a bearer secret, and
     * rendering this URL would bake it into the page's canonical and og:url
     * tags, its browser history entry, and the Referer header of every outbound
     * link on it. The result is handed to the plain /verify page instead.
     */
    public function token(string $token): RedirectResponse
    {
        $receipt = $this->verifier->findByToken($token);

        if (! $receipt) {
            return redirect()->route('receipts.verify')->with('verified_missing', true);
        }

        $this->verifier->recordCheck($receipt);

        return redirect()->route('receipts.verify')->with('verified_receipt_id', $receipt->getKey());
    }
}
