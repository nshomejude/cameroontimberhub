<?php

namespace App\Services;

use App\Enums\TimberCategory;
use App\Models\Species;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single query behind the public timber species directory (/species).
 *
 * Every facet here is a REAL derivation over seeded catalogue data — there are
 * no hard-coded counts. The `properties` and `applications` groups are derived
 * from `durability_class`, `density_kg_m3_*`, `characteristics` and
 * `typical_uses`; the derivation for each is stated in `facetDefinitions()` and
 * is the same predicate used both to count the facet and to filter by it, so a
 * count can never disagree with the result set it produces.
 *
 * A facet whose real count is zero is dropped from the sidebar rather than
 * rendered as an empty promise.
 */
class SpeciesDirectoryService
{
    /**
     * Facet predicates, keyed by group then value.
     *
     * `sql` is a raw WHERE fragment evaluated against the `species` table. The
     * jsonb columns are cast to text and matched case-insensitively, which is
     * enough for these coarse "does this species serve this use" buckets and
     * keeps the predicate identical between counting and filtering.
     *
     * @return array<string, array<string, array{label: string, sql: string}>>
     */
    public static function facetDefinitions(): array
    {
        return [
            'properties' => [
                // EN 350 natural-durability classes 1 and 2 (heartwood).
                'durable' => [
                    'label' => 'High Durability',
                    'sql' => "(durability_class ILIKE 'Class 1%' OR durability_class ILIKE 'Class 2 (%')",
                ],
                // Recorded resistance to termites / insect attack in the species notes.
                'insect-resistant' => [
                    'label' => 'Termite & Insect Resistant',
                    'sql' => "(description ILIKE '%termite%' OR description ILIKE '%insect%')",
                ],
                // Species the catalogue records as used in water, marine or hydraulic work.
                'water-resistant' => [
                    'label' => 'Water & Marine Resistant',
                    'sql' => "(description ILIKE '%marine%' OR description ILIKE '%hydraulic%' OR typical_uses::text ILIKE '%marine%' OR typical_uses::text ILIKE '%boat%')",
                ],
                'easy-to-work' => [
                    'label' => 'Easy to Work',
                    'sql' => "(characteristics::text ILIKE '%easil%' OR characteristics::text ILIKE '%easy%' OR description ILIKE '%works easily%' OR description ILIKE '%easy to work%')",
                ],
                // Figured / striped / streaked grain, or a recorded veneer use.
                'decorative' => [
                    'label' => 'Decorative Grain',
                    'sql' => "(characteristics::text ILIKE '%figure%' OR characteristics::text ILIKE '%ribbon%' OR characteristics::text ILIKE '%stripe%' OR characteristics::text ILIKE '%streak%' OR typical_uses::text ILIKE '%veneer%')",
                ],
                'lightweight' => [
                    'label' => 'Lightweight (≤ 500 kg/m³)',
                    'sql' => '(density_kg_m3_max IS NOT NULL AND density_kg_m3_max <= 500)',
                ],
            ],
            'applications' => [
                'furniture' => ['label' => 'Furniture', 'sql' => "(typical_uses::text ILIKE '%furniture%' OR typical_uses::text ILIKE '%cabinet%')"],
                'flooring' => ['label' => 'Flooring', 'sql' => "(typical_uses::text ILIKE '%floor%')"],
                'construction' => ['label' => 'Construction', 'sql' => "(typical_uses::text ILIKE '%construction%' OR typical_uses::text ILIKE '%structural%')"],
                'joinery' => ['label' => 'Joinery', 'sql' => "(typical_uses::text ILIKE '%joinery%' OR typical_uses::text ILIKE '%door%' OR typical_uses::text ILIKE '%window%')"],
                'boat-building' => ['label' => 'Boat Building', 'sql' => "(typical_uses::text ILIKE '%boat%' OR typical_uses::text ILIKE '%marine%')"],
                'veneer' => ['label' => 'Veneer & Plywood', 'sql' => "(typical_uses::text ILIKE '%veneer%' OR typical_uses::text ILIKE '%plywood%')"],
                'decking' => ['label' => 'Decking', 'sql' => "(typical_uses::text ILIKE '%decking%')"],
                'musical' => ['label' => 'Musical Instruments', 'sql' => "(typical_uses::text ILIKE '%musical%' OR typical_uses::text ILIKE '%instrument%')"],
            ],
        ];
    }

    /** Sort options exposed by the directory toolbar. @return array<string, string> */
    public static function sortOptions(): array
    {
        return [
            'popularity' => 'Popularity',
            'name' => 'Name (A–Z)',
            'density' => 'Density (heaviest)',
            'durability' => 'Durability',
        ];
    }

    /** @param array<string, mixed> $filters */
    public function search(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * The directory query. Livewire (interactive filtering) and the controller
     * (server-rendered JSON-LD) read through it so the page's structured data
     * always describes exactly what the visitor sees.
     *
     * Facet groups AND together, and values inside a group OR together — the
     * conventional faceted-browse semantics.
     *
     * @param  array{q?: string, categories?: list<string>, properties?: list<string>, applications?: list<string>, region?: string, inStock?: bool, sort?: string}  $filters
     */
    public function query(array $filters): Builder
    {
        $query = $this->applyFilters($this->base(), $filters);

        return match ($filters['sort'] ?? 'popularity') {
            'name' => $query->orderBy('common_name'),
            'density' => $query->orderByRaw('density_kg_m3_max DESC NULLS LAST')->orderBy('common_name'),
            'durability' => $query->orderByRaw('durability_class ASC NULLS LAST')->orderBy('common_name'),
            default => $query->orderByDesc('products_count')->orderBy('sort_order')->orderBy('common_name'),
        };
    }

    /**
     * Published species with a real count of the active products that publicly
     * visible companies list against them.
     */
    public function base(): Builder
    {
        return Species::published()->withCount([
            'products' => fn ($p) => $p->active()->whereHas('company', fn ($c) => $c->publiclyVisible()),
        ]);
    }

    /**
     * Facet counts are computed against the free-text + region context only, so
     * ticking one box in a list does not zero out its siblings. Deliberately
     * unordered and without the products sub-select: these builders are only
     * ever counted.
     *
     * @param  array{q?: string, region?: string}  $context
     */
    public function facetBase(array $context): Builder
    {
        return $this->applyFilters(Species::published(), [
            'q' => $context['q'] ?? '',
            'region' => $context['region'] ?? '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $region = trim((string) ($filters['region'] ?? ''));

        $query
            ->when($q !== '', function (Builder $b) use ($q): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';

                $b->where(fn (Builder $inner) => $inner
                    ->where('common_name', 'ilike', $like)
                    ->orWhere('scientific_name', 'ilike', $like)
                    ->orWhere('family', 'ilike', $like)
                    ->orWhereRaw('trade_names::text ILIKE ?', [$like])
                    ->orWhereRaw('local_names::text ILIKE ?', [$like]));
            })
            ->when(
                array_values(array_filter((array) ($filters['categories'] ?? []))),
                fn (Builder $b, array $c) => $b->whereIn('commercial_category', $c)
            )
            ->when($region !== '', fn (Builder $b) => $b->whereRaw('region_availability @> ?::jsonb', [json_encode([$region])]))
            ->when(
                ! empty($filters['inStock']),
                fn (Builder $b) => $b->whereHas('products', fn ($p) => $p->active()->whereHas('company', fn ($c) => $c->publiclyVisible()))
            );

        foreach (['properties', 'applications'] as $group) {
            $selected = array_values(array_filter((array) ($filters[$group] ?? [])));

            if ($selected === []) {
                continue;
            }

            $query->where(function (Builder $b) use ($group, $selected): void {
                // An unknown facet value must narrow to nothing rather than
                // silently widening the result set, hence the false seed.
                $b->whereRaw('false');

                foreach ($selected as $value) {
                    $sql = self::facetDefinitions()[$group][$value]['sql'] ?? null;

                    if ($sql !== null) {
                        $b->orWhereRaw($sql);
                    }
                }
            });
        }

        return $query;
    }

    /**
     * @param  array{q?: string, region?: string}  $context
     * @param  list<string>  $selected
     * @return list<array{value: string, label: string, count: int}>
     */
    public function facets(string $group, array $context, array $selected = []): array
    {
        return collect(self::facetDefinitions()[$group] ?? [])
            ->map(fn (array $def, string $value) => [
                'value' => $value,
                'label' => $def['label'],
                'count' => $this->facetBase($context)->whereRaw($def['sql'])->count(),
            ])
            // Omit a facet with no backing data rather than fake it; a selected
            // facet stays visible so the visitor can clear it.
            ->filter(fn (array $row): bool => $row['count'] > 0 || in_array($row['value'], $selected, true))
            ->values()
            ->all();
    }

    /**
     * @param  array{q?: string, region?: string}  $context
     * @param  list<string>  $selected
     * @return list<array{value: string, label: string, count: int}>
     */
    public function categoryFacets(array $context, array $selected = []): array
    {
        $counts = $this->facetBase($context)
            ->toBase()
            ->select('commercial_category')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('commercial_category')
            ->pluck('aggregate', 'commercial_category');

        return collect(TimberCategory::cases())
            ->map(fn (TimberCategory $c) => [
                'value' => $c->value,
                'label' => $c->shortLabel(),
                'count' => (int) ($counts[$c->value] ?? 0),
            ])
            ->filter(fn (array $row): bool => $row['count'] > 0 || in_array($row['value'], $selected, true))
            ->values()
            ->all();
    }

    /**
     * Distinct harvesting regions recorded across the catalogue, with counts.
     *
     * @return Collection<int, array{value: string, count: int}>
     */
    public function regionFacets(): Collection
    {
        $rows = Species::query()->getConnection()->select(
            'select jsonb_array_elements_text(region_availability) as region, count(*) as aggregate
             from species
             where is_published = true and region_availability is not null
             group by 1 order by 2 desc, 1 asc'
        );

        return collect($rows)->map(fn ($row) => ['value' => $row->region, 'count' => (int) $row->aggregate]);
    }

    /**
     * "In Stock" availability facet — species that at least one publicly
     * visible company actively lists a product for. Null when nothing in the
     * catalogue is stocked yet, in which case the section is not rendered.
     *
     * @param  array{q?: string, region?: string}  $context
     * @return array{value: string, label: string, count: int}|null
     */
    public function inStockFacet(array $context): ?array
    {
        $count = $this->facetBase($context)
            ->whereHas('products', fn ($p) => $p->active()->whereHas('company', fn ($c) => $c->publiclyVisible()))
            ->count();

        return $count > 0 ? ['value' => '1', 'label' => 'In Stock', 'count' => $count] : null;
    }
}
