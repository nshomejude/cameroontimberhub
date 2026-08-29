<?php

namespace App\Services;

use App\Enums\RfqCurrency;
use App\Enums\RfqIncoterm;
use App\Enums\RfqType;
use App\Enums\RfqUnit;
use App\Enums\TimberForm;
use App\Models\Company;
use App\Models\Species;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;

/**
 * Session-backed state for the multi-step RFQ wizard.
 *
 * Deliberately *not* Livewire: the RFQ intake is the platform's only conversion
 * surface and is open to anonymous buyers on poor connections, so every step is
 * a plain server-rendered form that POSTs and redirects. That gives refresh
 * survival, working browser back/forward (each step is its own GET URL) and a
 * no-JavaScript path for free — see RfqController.
 *
 * The wizard only ever *accumulates* input. The single write path to the
 * database remains IntakeService::createRfq(), called once from
 * RfqController::store().
 */
class RfqWizard
{
    public const KEY = 'rfq_wizard';

    /** Ordered step definitions: slug => [label, caption]. */
    public const STEPS = [
        'details' => ['RFQ Details', 'Basic information'],
        'products' => ['Products & Requirements', 'What you need'],
        'delivery' => ['Delivery & Terms', 'Business terms'],
        'contact' => ['Your Details', 'Who to contact'],
        'review' => ['Review & Submit', 'Final review'],
    ];

    public const MAX_ITEMS = 10;

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::STEPS);
    }

    public static function isStep(string $step): bool
    {
        return array_key_exists($step, self::STEPS);
    }

    public static function indexOf(string $step): int
    {
        return (int) array_search($step, self::slugs(), true);
    }

    public static function next(string $step): ?string
    {
        return self::slugs()[self::indexOf($step) + 1] ?? null;
    }

    public static function previous(string $step): ?string
    {
        $i = self::indexOf($step);

        return $i > 0 ? self::slugs()[$i - 1] : null;
    }

    /* ---------------------------------------------------------------- state */

    /** @return array<string, mixed> */
    public function all(): array
    {
        return (array) Session::get(self::KEY, []);
    }

    /** @return array<string, mixed> */
    public function step(string $step): array
    {
        return (array) Arr::get($this->all(), $step, []);
    }

    /** @param array<string, mixed> $data */
    public function putStep(string $step, array $data): void
    {
        $state = $this->all();
        $state[$step] = $data;
        Session::put(self::KEY, $state);
    }

    public function clear(): void
    {
        Session::forget(self::KEY);
    }

    /**
     * Which RFQ flow this session's wizard is filling in — the original
     * export flow (default) or the domestic manufacturing / local
     * procurement / project flow (gap-plan 1.5.5). Both are the same wizard;
     * only the tag carried on the final row differs.
     */
    public function type(): RfqType
    {
        return RfqType::tryFrom((string) Arr::get($this->all(), 'type')) ?? RfqType::Export;
    }

    public function putType(RfqType $type): void
    {
        $state = $this->all();
        $state['type'] = $type->value;
        Session::put(self::KEY, $state);
    }

    /**
     * A step counts as complete only once its required fields are banked —
     * pre-filled partials (a signed-in buyer's name and email, a shortlist's
     * products) must not let the visitor skip past a step they never saw.
     */
    public function completed(string $step): bool
    {
        $data = $this->step($step);

        return match ($step) {
            'details' => filled($data['title'] ?? null),
            'products' => $this->items() !== [],
            'delivery' => filled($data['destination_country_code'] ?? null) && filled($data['notes'] ?? null),
            'contact' => filled($data['buyer_email'] ?? null) && filled($data['consent'] ?? null),
            default => $data !== [],
        };
    }

    /**
     * The furthest step the visitor may open: one past the last completed step.
     * Deep-linking beyond it (or hitting /review with an empty basket) bounces
     * back to the first incomplete step rather than rendering a broken summary.
     */
    public function furthestReachable(): string
    {
        foreach (self::slugs() as $slug) {
            if ($slug === 'review' || ! $this->completed($slug)) {
                return $slug;
            }
        }

        return 'review';
    }

    public function reachable(string $step): bool
    {
        return self::indexOf($step) <= self::indexOf($this->furthestReachable());
    }

    /** Every collected field, flattened — the payload the final submit validates. */
    public function payload(): array
    {
        $state = $this->all();

        return array_merge(
            Arr::except((array) ($state['details'] ?? []), ['items']),
            (array) ($state['delivery'] ?? []),
            (array) ($state['contact'] ?? []),
            ['items' => $this->items()],
        );
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return array_values((array) Arr::get($this->all(), 'products.items', []));
    }

    /**
     * Raw, not-yet-valid line items kept while the visitor is adding or
     * removing rows. This is what makes the repeater work with JavaScript
     * disabled: "Add product" is a real submit that banks the draft and
     * re-renders the step with one more row.
     *
     * @return list<array<string, mixed>>|null
     */
    public function draftItems(): ?array
    {
        $draft = Arr::get($this->all(), 'products.draft');

        return is_array($draft) ? array_values($draft) : null;
    }

    /** @param list<array<string, mixed>> $items */
    public function putDraftItems(array $items): void
    {
        $state = $this->all();
        $state['products']['draft'] = array_values(array_slice($items, 0, self::MAX_ITEMS));
        Session::put(self::KEY, $state);
    }

    public function clearDraftItems(): void
    {
        $state = $this->all();
        unset($state['products']['draft']);
        Session::put(self::KEY, $state);
    }

    /** The rows the products step should render right now. */
    public function editableItems(): array
    {
        $rows = $this->draftItems() ?? $this->items();

        return $rows === [] ? [[]] : $rows;
    }

    /* ----------------------------------------------------------- validation */

    /**
     * Rules for one step. `null` step means "everything", used by the final
     * submit so a hand-crafted single POST is validated just as strictly.
     *
     * @return array<string, mixed>
     */
    public static function rules(?string $step = null): array
    {
        $sets = [
            'details' => [
                'title' => ['required', 'string', 'min:3', 'max:160'],
                'project_name' => ['nullable', 'string', 'max:160'],
                'deadline' => ['nullable', 'date', 'after_or_equal:today'],
            ],
            'products' => [
                'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
                'items.*.species_id' => ['nullable', 'integer', 'exists:species,id'],
                'items.*.species_text' => ['nullable', 'string', 'max:160', 'required_without:items.*.species_id'],
                'items.*.form' => ['required', Rule::in(array_column(TimberForm::cases(), 'value'))],
                'items.*.grade' => ['nullable', 'string', 'max:60'],
                'items.*.dimensions' => ['nullable', 'string', 'max:160'],
                'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
                'items.*.unit' => ['required', Rule::in(RfqUnit::values())],
                'items.*.moisture_content' => ['nullable', 'string', 'max:60'],
            ],
            'delivery' => [
                'destination_country_code' => ['required', 'string', 'size:2', 'alpha'],
                'shipping_port' => ['nullable', 'string', 'max:160'],
                'incoterm' => ['nullable', Rule::in(array_column(RfqIncoterm::cases(), 'value'))],
                'target_amount' => ['nullable', 'numeric', 'min:0'],
                'target_currency' => ['nullable', 'required_with:target_amount', Rule::in(RfqCurrency::values())],
                'notes' => ['required', 'string', 'min:20', 'max:4000'],
            ],
            'contact' => [
                'buyer_name' => ['required', 'string', 'min:2', 'max:120'],
                'buyer_email' => ['required', 'email:rfc', 'max:180'],
                'buyer_company' => ['nullable', 'string', 'max:160'],
                'buyer_phone' => ['nullable', 'string', 'regex:/^\+?[1-9]\d{6,14}$/'],
                'buyer_country_code' => ['required', 'string', 'size:2', 'alpha'],
                'consent' => ['accepted'],
            ],
            'review' => [],
        ];

        if ($step !== null) {
            return $sets[$step] ?? [];
        }

        $all = array_merge(...array_values($sets));

        // The wizard always collects a title at step 1, but the pre-wizard
        // single-payload POST never had that field. Relaxing it here keeps
        // those callers working; RfqController::store() derives a headline
        // from the first line item when one is missing.
        $all['title'] = ['nullable', 'string', 'min:3', 'max:160'];

        return $all;
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'title' => 'RFQ title',
            'project_name' => 'project name',
            'deadline' => 'response deadline',
            'destination_country_code' => 'destination country',
            'buyer_country_code' => 'your country',
            'buyer_name' => 'your name',
            'buyer_email' => 'your email',
            'notes' => 'requirement details',
            'items' => 'products',
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'consent.accepted' => 'Please confirm you agree before submitting.',
            'items.*.species_text.required_without' => 'Choose a species from the catalogue or type one.',
            'items.min' => 'Add at least one product to your request.',
        ];
    }

    /* -------------------------------------------------------- derived facts */

    /**
     * How many publicly visible (verified, complete, badged) suppliers list at
     * least one of the species in this request. A real query, not a promise:
     * the copy around it says "match", never "will receive", because routing is
     * an admin decision taken after the buyer confirms their email address.
     */
    public function matchingSupplierCount(): int
    {
        $speciesIds = array_values(array_filter(array_column($this->items(), 'species_id')));

        if ($speciesIds === []) {
            return 0;
        }

        return Company::publiclyVisible()
            ->whereHas('species', fn ($q) => $q->whereIn('species.id', $speciesIds))
            ->count();
    }

    /** Species rows referenced by the current basket, keyed by id. */
    public function speciesById(): Collection
    {
        $ids = array_values(array_filter(array_column($this->items(), 'species_id')));

        return $ids === []
            ? collect()
            : Species::whereKey($ids)->get(['id', 'slug', 'common_name'])->keyBy('id');
    }
}
