<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public print/PDF views of the platform's issued billing documents
 * (billing engine M4). Mirrors the order-receipt print view.
 *
 * Access: a member of the document's owning company, or platform finance
 * staff (`payments.view` / `payments.manage`). Anyone else → 403.
 *
 * `?format=pdf` renders through dompdf (barryvdh/laravel-dompdf, already a
 * dependency); the default HTML view is print-optimised (window.print()).
 */
class BillingDocumentController extends Controller
{
    public function invoice(Request $request, Invoice $invoice): Response
    {
        abort_unless($this->authorized($request->user(), $invoice->company_id), 403);

        $invoice->load(['lines', 'creditNotes']);

        if ($request->query('format') === 'pdf') {
            return Pdf::loadView('public.billing.invoice', ['invoice' => $invoice, 'pdf' => true])
                ->download($invoice->invoice_number.'.pdf');
        }

        return response()->view('public.billing.invoice', ['invoice' => $invoice, 'pdf' => false]);
    }

    public function creditNote(Request $request, CreditNote $creditNote): Response
    {
        abort_unless($this->authorized($request->user(), $creditNote->company_id), 403);

        $creditNote->load(['lines', 'invoice']);

        if ($request->query('format') === 'pdf') {
            return Pdf::loadView('public.billing.credit-note', ['creditNote' => $creditNote, 'pdf' => true])
                ->download($creditNote->credit_note_number.'.pdf');
        }

        return response()->view('public.billing.credit-note', ['creditNote' => $creditNote, 'pdf' => false]);
    }

    private function authorized(?User $user, int $companyId): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->can('payments.view') || $user->can('payments.manage')) {
            return true;
        }

        return $user->companies()->whereKey($companyId)->exists();
    }
}
