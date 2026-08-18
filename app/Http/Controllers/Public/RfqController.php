<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Rfq;
use App\Models\Species;
use App\Services\IntakeService;
use App\Services\RfqList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RfqController extends Controller
{
    public function create(Request $request, RfqList $list): View
    {
        $shortlist = $list->products();

        // The shortlist drives the form: the first item pre-fills the single
        // structured line, and every item is echoed into the notes so the
        // supplier sees exactly what the buyer shortlisted.
        $first = $shortlist->first();

        return view('public.rfq.create', [
            'species' => Species::published()->orderBy('common_name')->get(['id', 'slug', 'common_name']),
            'prefillSpecies' => $request->query('species'),
            'shortlist' => $shortlist,
            'prefillSpeciesId' => $first?->species_id,
            'prefillQuantity' => $first?->moq_quantity !== null ? rtrim(rtrim(number_format((float) $first->moq_quantity, 2, '.', ''), '0'), '.') : null,
            'prefillNotes' => $shortlist->isEmpty() ? null : 'I would like a quotation for the following listings:
'
                .$shortlist->map(fn ($p) => '- '.$p->name.' ('.($p->company?->name ?? 'supplier').')')->implode('
'),
            'formRenderedAt' => now()->timestamp,
        ]);
    }

    public function store(Request $request, IntakeService $intake, RfqList $list): RedirectResponse
    {
        // Honeypot/min-time: silent neutral success, no row written.
        if ($intake->honeypotTripped($request->all())) {
            return redirect()->route('rfq.thanks');
        }

        $data = $request->validate($this->rules());

        $header = [
            'buyer_name' => trim($data['buyer_name']),
            'buyer_company' => $data['buyer_company'] ?? null,
            'buyer_email' => strtolower(trim($data['buyer_email'])),
            'buyer_phone' => $data['buyer_phone'] ?? null,
            'buyer_country_code' => strtoupper($data['buyer_country_code']),
            'destination_country_code' => strtoupper($data['destination_country_code']),
            'incoterm' => $data['incoterm'] ?? null,
            'target_amount' => $data['target_amount'] ?? null,
            'target_currency' => isset($data['target_currency']) ? strtoupper($data['target_currency']) : null,
            'deadline' => $data['deadline'] ?? null,
            'shipping_port' => $data['shipping_port'] ?? null,
            'notes' => $data['notes'],
        ];

        $items = [[
            'species_id' => $data['species_id'] ?? null,
            'species_text' => $data['species_text'] ?? null,
            'form' => $data['form'],
            'grade' => $data['grade'] ?? null,
            'dimensions' => $data['dimensions'] ?? null,
            'quantity' => $data['quantity'],
            'unit' => $data['unit'],
            'moisture_content' => $data['moisture_content'] ?? null,
        ]];

        $intake->createRfq($header, $items, $data['source'] ?? 'request_quote');

        // The shortlist has been consumed by this request.
        $list->clear();

        return redirect()->route('rfq.thanks');
    }

    public function verify(Request $request, Rfq $rfq, IntakeService $intake): View
    {
        abort_unless($request->query('h') === sha1($rfq->buyer_email), 403);

        $intake->verifyRfq($rfq);

        return view('public.rfq.verified', ['rfq' => $rfq]);
    }

    public function thanks(): View
    {
        return view('public.rfq.thanks');
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'buyer_name' => ['required', 'string', 'min:2', 'max:120'],
            'buyer_email' => ['required', 'email:rfc', 'max:180'],
            'buyer_company' => ['nullable', 'string', 'max:160'],
            'buyer_country_code' => ['required', 'string', 'size:2'],
            'buyer_phone' => ['nullable', 'string', 'regex:/^\+?[1-9]\d{6,14}$/'],
            'destination_country_code' => ['required', 'string', 'size:2'],
            'shipping_port' => ['nullable', 'string', 'max:160'],
            'incoterm' => ['nullable', Rule::in(['EXW', 'FOB', 'CFR', 'CIF', 'DAP'])],
            'target_amount' => ['nullable', 'numeric', 'min:0'],
            'target_currency' => ['nullable', 'required_with:target_amount', Rule::in(['XAF', 'USD', 'EUR', 'GBP', 'CNY'])],
            'deadline' => ['nullable', 'date'],
            'notes' => ['required', 'string', 'min:20', 'max:4000'],
            'consent' => ['accepted'],
            'species_id' => ['nullable', 'exists:species,id'],
            'species_text' => ['nullable', 'string', 'max:160', 'required_without:species_id'],
            'form' => ['required', Rule::in(['logs', 'sawn', 'veneer', 'plywood', 'other'])],
            'grade' => ['nullable', 'string', 'max:60'],
            'dimensions' => ['nullable', 'string', 'max:160'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'unit' => ['required', Rule::in(['m3', 'ton', 'pcs', 'container'])],
            'moisture_content' => ['nullable', 'string', 'max:60'],
            'source' => ['nullable', 'string', 'max:40'],
            // Honeypot fields (validated leniently; logic handled in IntakeService).
            'website' => ['nullable'],
            'form_rendered_at' => ['nullable'],
        ];
    }
}
