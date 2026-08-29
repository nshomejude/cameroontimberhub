<?php

namespace App\Http\Controllers\Public;

use App\Enums\RfqCurrency;
use App\Enums\RfqIncoterm;
use App\Enums\RfqType;
use App\Enums\RfqUnit;
use App\Enums\TimberForm;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\Species;
use App\Services\IntakeService;
use App\Services\RfqList;
use App\Services\RfqWizard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * The public, account-free RFQ intake — a five-step, server-rendered wizard.
 *
 * Each step is its own GET URL backed by a plain POST, so refresh, browser
 * back/forward and no-JavaScript clients all work without special handling.
 * Nothing is written to the database until the final submit, which still goes
 * through IntakeService::createRfq() — the one write path, and the one that
 * mails the signed 48-hour verification link that gates the whole pipeline.
 */
class RfqController extends Controller
{
    public function create(Request $request, RfqWizard $wizard, RfqList $list): View|RedirectResponse
    {
        $this->seed($request, $wizard, $list);

        // Returning visitors land back where they left off, but /request-quote
        // itself always renders (never redirects) so it stays a linkable entry.
        return $this->render($request, $wizard, $list, 'details');
    }

    /**
     * The domestic manufacturing / local procurement / project RFQ entry
     * point (gap-plan 1.5.5). Same wizard, same steps, same views as the
     * export flow — the only difference is the `type` tag stamped into the
     * session before it starts, which rides through to `rfqs.type` on
     * submit. Multi-line-item support ("500 doors + 200 windows") is nothing
     * new: the products step has always accepted multiple rows.
     */
    public function createManufacturing(Request $request, RfqWizard $wizard, RfqList $list): View|RedirectResponse
    {
        $isFirstVisit = $wizard->all() === [];

        $this->seed($request, $wizard, $list);

        if ($isFirstVisit) {
            $wizard->putType(RfqType::DomesticManufacturing);
        }

        return $this->render($request, $wizard, $list, 'details');
    }

    /**
     * The transport RFQ entry point (gap-plan 1.5.10). Same wizard, same
     * steps as export/manufacturing — only the `type` tag stamped into the
     * session before it starts differs, which rides through to `rfqs.type`
     * on submit. Booking a resulting Order into a Shipment is a separate,
     * later step (ShipmentService), not part of the wizard itself.
     */
    public function createTransport(Request $request, RfqWizard $wizard, RfqList $list): View|RedirectResponse
    {
        $isFirstVisit = $wizard->all() === [];

        $this->seed($request, $wizard, $list);

        if ($isFirstVisit) {
            $wizard->putType(RfqType::Transport);
        }

        return $this->render($request, $wizard, $list, 'details');
    }

    public function step(Request $request, RfqWizard $wizard, RfqList $list, string $step): View|RedirectResponse
    {
        abort_unless(RfqWizard::isStep($step), 404);

        if (! $wizard->reachable($step)) {
            return redirect()->route('rfq.step', ['step' => $wizard->furthestReachable()])
                ->with('rfq_notice', 'Finish the earlier steps first.');
        }

        return $this->render($request, $wizard, $list, $step);
    }

    /** Validates one step, banks it in the session, and moves on (or back). */
    public function storeStep(Request $request, RfqWizard $wizard, string $step): RedirectResponse
    {
        abort_unless(RfqWizard::isStep($step) && $step !== 'review', 404);

        // Repeater controls are plain submit buttons, so adding or removing a
        // product row works with JavaScript switched off: bank the raw draft,
        // redirect, re-render. No validation runs — half-typed rows survive.
        if ($step === 'products' && ($request->has('add_item') || $request->filled('remove_item'))) {
            $rows = array_values((array) $request->input('items', []));

            if ($request->filled('remove_item')) {
                unset($rows[(int) $request->input('remove_item')]);
                $rows = array_values($rows);
            } elseif (count($rows) < RfqWizard::MAX_ITEMS) {
                $rows[] = [];
            }

            $wizard->putDraftItems($rows);

            return redirect()->route('rfq.step', ['step' => 'products']);
        }

        $input = $this->normalise($request->all());
        $goingBack = $request->input('direction') === 'back';

        $validator = Validator::make($input, RfqWizard::rules($step), RfqWizard::messages(), RfqWizard::attributes());

        if ($validator->fails()) {
            // Keep the rows the visitor actually typed, so a rejected 3-product
            // basket comes back as 3 rows rather than collapsing to one.
            if ($step === 'products') {
                $wizard->putDraftItems(array_values((array) $request->input('items', [])));
            }

            // Stepping backwards never blocks on errors — the visitor keeps
            // whatever was already banked and simply returns to the prior step.
            if ($goingBack) {
                return redirect()->route('rfq.step', ['step' => RfqWizard::previous($step)]);
            }

            return back()->withErrors($validator)->withInput();
        }

        $wizard->putStep($step, $validator->validated());

        $target = $goingBack ? RfqWizard::previous($step) : RfqWizard::next($step);

        return redirect()->route('rfq.step', ['step' => $target ?? 'review']);
    }

    public function store(Request $request, IntakeService $intake, RfqWizard $wizard, RfqList $list): RedirectResponse
    {
        // Honeypot / min-time: silent neutral success, no row written.
        if ($intake->honeypotTripped($request->all())) {
            $wizard->clear();

            return redirect()->route('rfq.thanks');
        }

        // The wizard's banked state is the base; anything posted with the final
        // request wins. A single full-payload POST (no wizard) therefore still
        // validates and submits exactly as it did before the wizard existed.
        $input = $this->normalise(array_merge($wizard->payload(), array_filter(
            $request->except(['_token', 'direction']),
            fn ($v) => $v !== null && $v !== '',
        )));

        $validator = Validator::make($input, RfqWizard::rules(), RfqWizard::messages(), RfqWizard::attributes());

        if ($validator->fails()) {
            return redirect()->route('rfq.step', ['step' => $this->firstFailingStep($validator->errors()->keys())])
                ->withErrors($validator)->withInput();
        }

        $data = $validator->validated();

        // Legacy single-payload callers omit the wizard's headline; derive one
        // from the first line item so every RFQ still has a usable title.
        if (blank($data['title'] ?? null)) {
            $first = $data['items'][0] ?? [];
            $species = filled($first['species_id'] ?? null)
                ? Species::find($first['species_id'])?->common_name
                : ($first['species_text'] ?? null);

            $data['title'] = 'Quote request'.($species ? ' — '.$species : '');
        }

        $rfq = $intake->createRfq(
            array_merge(Arr::only($data, [
                'title', 'project_name', 'buyer_name', 'buyer_company', 'buyer_phone',
                'incoterm', 'target_amount', 'deadline', 'shipping_port', 'notes',
            ]), [
                'type' => $wizard->type()->value,
                'buyer_name' => trim($data['buyer_name']),
                'buyer_email' => strtolower(trim($data['buyer_email'])),
                'buyer_country_code' => strtoupper($data['buyer_country_code']),
                'destination_country_code' => strtoupper($data['destination_country_code']),
                'target_currency' => isset($data['target_currency']) ? strtoupper($data['target_currency']) : null,
            ]),
            array_map(fn (array $i) => Arr::only($i, [
                'species_id', 'species_text', 'form', 'grade', 'dimensions', 'quantity', 'unit', 'moisture_content',
            ]), $data['items']),
            $request->input('source', 'request_quote'),
            filled($data['consent'] ?? null),
        );

        // Both baskets have been consumed by this request.
        $wizard->clear();
        $list->clear();

        return redirect()->route('rfq.thanks')->with('rfq_submitted', [
            'reference' => $rfq->reference_code,
            'email' => $rfq->buyer_email,
            'title' => $rfq->title,
            'items' => $rfq->items->count(),
            'submitted_at' => $rfq->created_at?->toIso8601String(),
        ]);
    }

    /**
     * End state one: submitted, awaiting email confirmation. Nothing has been
     * sent to any supplier yet and the copy here says so.
     */
    public function thanks(Request $request): View
    {
        return view('public.rfq.thanks', [
            'submitted' => (array) $request->session()->get('rfq_submitted', []),
        ]);
    }

    /**
     * End state two: the signed link was opened, the address is confirmed, and
     * the request has entered the moderation queue. Still not "sent to N
     * suppliers" — routing is an admin action taken after approval.
     */
    public function verify(Request $request, Rfq $rfq, IntakeService $intake): View
    {
        abort_unless($request->query('h') === sha1($rfq->buyer_email), 403);

        $intake->verifyRfq($rfq);

        return view('public.rfq.verified', ['rfq' => $rfq->fresh()->load('items.species')]);
    }

    /* ------------------------------------------------------------- internals */

    private function render(Request $request, RfqWizard $wizard, RfqList $list, string $step): View
    {
        return view('public.rfq.wizard', [
            'step' => $step,
            'wizard' => $wizard,
            'shortlist' => $list->products(),
            'species' => Species::published()->orderBy('common_name')->get(['id', 'slug', 'common_name']),
            'forms' => TimberForm::cases(),
            'units' => RfqUnit::cases(),
            'incoterms' => RfqIncoterm::cases(),
            'currencies' => RfqCurrency::cases(),
            'speciesById' => $wizard->speciesById(),
            'matchingSuppliers' => $wizard->matchingSupplierCount(),
            'formRenderedAt' => now()->timestamp,
        ]);
    }

    /**
     * Accepts the legacy single-payload shape (flat species/form/quantity/unit)
     * and normalises it into the wizard's `items` array so one ruleset covers
     * both. Also drops blank rows left behind by the "add product" control.
     */
    private function normalise(array $input): array
    {
        $items = array_values(array_filter(
            (array) ($input['items'] ?? []),
            fn ($i) => is_array($i) && (filled($i['species_id'] ?? null) || filled($i['species_text'] ?? null) || filled($i['quantity'] ?? null)),
        ));

        if ($items === [] && (filled($input['species_id'] ?? null) || filled($input['species_text'] ?? null))) {
            $items = [Arr::only($input, [
                'species_id', 'species_text', 'form', 'grade', 'dimensions', 'quantity', 'unit', 'moisture_content',
            ])];
        }

        if ($items !== []) {
            $input['items'] = array_map(fn (array $i) => array_map(
                fn ($v) => $v === '' ? null : $v,
                $i,
            ), $items);
        } else {
            unset($input['items']);
        }

        return $input;
    }

    /** @param list<string> $failedKeys */
    private function firstFailingStep(array $failedKeys): string
    {
        foreach (RfqWizard::slugs() as $slug) {
            $fields = array_keys(RfqWizard::rules($slug));

            foreach ($failedKeys as $key) {
                foreach ($fields as $field) {
                    if ($key === $field || str_starts_with($key, rtrim(strtok($field, '*'), '.').'.')) {
                        return $slug;
                    }
                }
            }
        }

        return 'review';
    }

    /**
     * First visit only: pre-fill from the session RFQ shortlist, from a
     * ?species= deep link, and from the signed-in buyer's own details. Never
     * required — the intake stays open to guests.
     */
    private function seed(Request $request, RfqWizard $wizard, RfqList $list): void
    {
        if ($wizard->all() !== []) {
            return;
        }

        if ($user = $request->user()) {
            $wizard->putStep('contact', array_filter([
                'buyer_name' => $user->name,
                'buyer_email' => $user->email,
                'buyer_company' => $user->companies()->first()?->name,
                // Consent is a fresh, explicit act every time — never pre-ticked.
            ]));
        }

        $shortlist = $list->products();

        if ($shortlist->isNotEmpty()) {
            $wizard->putStep('products', ['items' => $shortlist
                ->take(RfqWizard::MAX_ITEMS)
                ->map(fn (Product $p) => [
                    'species_id' => $p->species_id,
                    'species_text' => $p->species_id ? null : $p->name,
                    'form' => $this->formFor($p)->value,
                    'grade' => null,
                    'dimensions' => null,
                    'quantity' => $p->moq_quantity !== null ? (float) $p->moq_quantity : null,
                    'unit' => in_array($p->moq_unit, RfqUnit::values(), true) ? $p->moq_unit : RfqUnit::CubicMetre->value,
                    'moisture_content' => null,
                ])->values()->all()]);

            $wizard->putStep('delivery', ['notes' => "I would like a quotation for the following listings:\n"
                .$shortlist->map(fn (Product $p) => '- '.$p->name.' ('.($p->company?->name ?? 'supplier').')')->implode("\n")]);
        }

        if (($slug = $request->query('species')) && ! $wizard->completed('products')) {
            $species = Species::published()->where('slug', $slug)->first();

            if ($species) {
                $wizard->putStep('products', ['items' => [[
                    'species_id' => $species->id,
                    'form' => TimberForm::Sawn->value,
                    'unit' => RfqUnit::CubicMetre->value,
                ]]]);
            }
        }
    }

    /** Maps a catalogue product type onto the RFQ line-item timber form. */
    private function formFor(Product $product): TimberForm
    {
        return match ($product->product_type?->value ?? $product->product_type) {
            'logs' => TimberForm::Logs,
            'veneer' => TimberForm::Veneer,
            'plywood' => TimberForm::Plywood,
            'sawn_timber', 'beams', 'planks', 'flooring', 'decking', 'mouldings' => TimberForm::Sawn,
            default => TimberForm::Other,
        };
    }
}
