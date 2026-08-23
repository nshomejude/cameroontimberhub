<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Species;
use App\Services\RfqWizard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * RFQ creation payload for the mobile client.
 *
 * The rules are RfqWizard's own — the whole wizard's rule set, flattened —
 * minus the two browser-form artefacts: the `consent` checkbox (the native
 * client presents its own consent copy) and the contact *identity* block, which
 * the API takes from the authenticated account rather than from the request
 * body. A buyer cannot raise an RFQ under someone else's name or address
 * through this endpoint, because those fields are not accepted at all.
 *
 * `buyer_company` is the exception and deliberately so: it is descriptive, not
 * identifying — the organisation the buyer is purchasing for, which the web
 * wizard collects on the contact step and stores in `rfqs.buyer_company`.
 * Accepting it here matches the web; dropping it silently, as this request used
 * to, was the worst of the three options.
 *
 * Species on a line item may be given three ways, in this order of precedence:
 *
 *   - `species_slug` — the catalogue slug the client actually holds, since the
 *     `/species` endpoints are keyed by slug and never expose numeric ids.
 *     Resolved here against PUBLISHED species only; an unknown or unpublished
 *     slug is a 422 on `items.N.species_slug`, never a silently null link.
 *   - `species_id` — kept working for callers that already have an id.
 *   - `species_text` — free text, for timber that is legitimately not in the
 *     catalogue. Still required when neither of the above is given.
 */
class StoreRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = RfqWizard::rules();

        // Identity comes from the token, never the body. `buyer_company` is
        // NOT identity — it stays, and is persisted (see the class docblock).
        unset(
            $rules['consent'],
            $rules['buyer_name'],
            $rules['buyer_email'],
            $rules['buyer_phone'],
        );

        $rules['title'] = ['required', 'string', 'min:3', 'max:160'];

        // Slug is the catalogue handle the API actually exposes. Validity is
        // checked in the `after` hook below so the error can say *why*, and so
        // "unpublished" and "nonexistent" give the same answer.
        $rules['items.*.species_slug'] = ['nullable', 'string', 'max:160'];

        // One of the three species inputs must be present. Free text remains a
        // first-class option for timber the catalogue does not list.
        $rules['items.*.species_text'] = [
            'nullable', 'string', 'max:160',
            'required_without_all:items.*.species_id,items.*.species_slug',
        ];

        // Anti-spam fields the API client may still send (honeypot + render
        // timestamp); IntakeService inspects them before anything is written.
        $rules['website'] = ['nullable', 'string', 'max:255'];
        $rules['form_rendered_at'] = ['nullable', 'integer'];

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return RfqWizard::attributes();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return array_merge(RfqWizard::messages(), [
            'items.*.species_text.required_without_all' => 'Give a species: send species_slug from the catalogue, or type the name in species_text.',
        ]);
    }

    /**
     * Resolve every `species_slug` before the request is considered valid, so
     * an unresolvable slug fails loudly on its own field instead of being
     * quietly written as a null catalogue link.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $items = $this->input('items');

            if (! is_array($items)) {
                return;
            }

            $resolved = $this->speciesBySlug();

            foreach ($items as $index => $item) {
                $slug = is_array($item) ? ($item['species_slug'] ?? null) : null;

                if (! is_string($slug) || trim($slug) === '') {
                    continue;
                }

                if (! $resolved->has(strtolower(trim($slug)))) {
                    // An unpublished species answers exactly as a nonexistent
                    // one does — the catalogue's draft rows are not discoverable
                    // by probing this endpoint.
                    $validator->errors()->add(
                        "items.{$index}.species_slug",
                        "\"{$slug}\" is not a species in our catalogue. Choose a listed species, or send the name as species_text instead.",
                    );
                }
            }
        });
    }

    /**
     * The line items as IntakeService wants them: `species_slug` collapsed into
     * the real `species_id`, and only the columns `rfq_items` actually has.
     *
     * @return list<array<string, mixed>>
     */
    public function itemsForIntake(): array
    {
        $resolved = $this->speciesBySlug();

        return array_map(function (array $item) use ($resolved): array {
            $slug = is_string($item['species_slug'] ?? null) ? strtolower(trim($item['species_slug'])) : '';

            return [
                // Slug wins when given: it is the handle the client can actually
                // see, and it has just been proven to resolve.
                'species_id' => $resolved->get($slug)?->getKey() ?? ($item['species_id'] ?? null),
                'species_text' => $item['species_text'] ?? null,
                'form' => $item['form'],
                'grade' => $item['grade'] ?? null,
                'dimensions' => $item['dimensions'] ?? null,
                'quantity' => $item['quantity'],
                'unit' => $item['unit'],
                'moisture_content' => $item['moisture_content'] ?? null,
            ];
        }, $this->validated()['items']);
    }

    /**
     * Published species for the slugs in this payload, keyed by lower-cased
     * slug. Resolved once per request — one query, however many line items.
     *
     * @return Collection<string, Species>
     */
    private function speciesBySlug(): Collection
    {
        if ($this->resolvedSpecies !== null) {
            return $this->resolvedSpecies;
        }

        $slugs = collect(is_array($this->input('items')) ? $this->input('items') : [])
            ->map(fn ($item) => is_array($item) ? ($item['species_slug'] ?? null) : null)
            ->filter(fn ($slug) => is_string($slug) && trim($slug) !== '')
            ->map(fn (string $slug) => strtolower(trim($slug)))
            ->unique()
            ->values();

        return $this->resolvedSpecies = $slugs->isEmpty()
            ? collect()
            : Species::query()
                ->published()
                ->whereIn('slug', $slugs->all())
                ->get(['id', 'slug'])
                ->keyBy(fn (Species $species) => strtolower($species->slug));
    }

    /** @var Collection<string, Species>|null */
    private ?Collection $resolvedSpecies = null;
}
