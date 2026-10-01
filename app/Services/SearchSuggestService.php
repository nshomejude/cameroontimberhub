<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Instant (type-ahead) search backing GET /api/v1/search/suggest and the
 * website header combobox.
 *
 * Built for keystrokes, not for ranking quality: at most one small query per
 * bucket (+1 for product images), each capped at `limit` rows, results
 * cached for 60s by normalised query. Matching is case- and
 * accent-insensitive substring matching ("ébène" == "ebene"), backed by the
 * trigram expression indexes from the 2026_10_01_140000 migration.
 *
 * Unlike the FTS-based SearchService::crossSearch(), a product also matches on
 * its species names (common/scientific/French) and on its supplier's names —
 * typing "iroko" finds every Iroko listing even when the seller called it
 * "Sawn beams 50x150".
 *
 * Visibility: products must be `active` and belong to a publicly visible
 * company; suppliers go through Company::publiclyVisible(); species must be
 * published. Only public fields are returned.
 */
class SearchSuggestService
{
    public const MIN_CHARS = 2;

    public const MAX_LIMIT = 5;

    public const MAX_QUERY_LENGTH = 64;

    public const TYPES = ['products', 'suppliers', 'species'];

    private const CACHE_TTL = 60;

    private ?bool $unaccent = null;

    /** Lower-cased, whitespace-collapsed, length-capped query used for matching and as the cache key. */
    public static function normalize(?string $q): string
    {
        $q = preg_replace('/\s+/u', ' ', trim((string) $q)) ?? '';

        return mb_substr(mb_strtolower($q), 0, self::MAX_QUERY_LENGTH);
    }

    /**
     * @param  list<string>  $types
     * @return array{products: list<array<string, mixed>>, suppliers: list<array<string, mixed>>, species: list<array<string, mixed>>}
     */
    public function suggest(string $q, array $types = self::TYPES, int $limit = self::MAX_LIMIT): array
    {
        $q = self::normalize($q);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $types = array_values(array_intersect(self::TYPES, $types)) ?: self::TYPES;

        $empty = ['products' => [], 'suppliers' => [], 'species' => []];

        if (mb_strlen($q) < self::MIN_CHARS) {
            return $empty;
        }

        $key = 'search-suggest:v1:'.md5($q.'|'.implode(',', $types).'|'.$limit.'|'.app()->getLocale());

        return Cache::remember($key, self::CACHE_TTL, function () use ($q, $types, $limit, $empty): array {
            $tokens = array_slice(array_values(array_filter(explode(' ', $q), fn (string $t) => $t !== '')), 0, 4);

            return array_merge($empty, [
                'products' => in_array('products', $types, true) ? $this->products($tokens, $q, $limit) : [],
                'suppliers' => in_array('suppliers', $types, true) ? $this->suppliers($tokens, $q, $limit) : [],
                'species' => in_array('species', $types, true) ? $this->species($tokens, $q, $limit) : [],
            ]);
        });
    }

    /** @param list<string> $tokens @return list<array<string, mixed>> */
    private function products(array $tokens, string $q, int $limit): array
    {
        $query = Product::query()
            ->select([
                'products.id', 'products.slug', 'products.name', 'products.primary_image_path',
                'products.price_amount', 'products.price_currency', 'products.price_unit',
                'products.species_id', 'products.company_id',
            ])
            ->addSelect([
                'species_name' => Species::query()->select('common_name')->whereColumn('species.id', 'products.species_id')->limit(1),
                'company_name' => Company::query()->select(DB::raw('coalesce(trade_name, legal_name)'))->whereColumn('companies.id', 'products.company_id')->limit(1),
            ])
            ->where('products.status', ProductStatus::Active)
            ->whereIn('products.company_id', Company::publiclyVisible()->select('companies.id'))
            ->with('images:id,product_id,path,alt,sort_order');

        foreach ($tokens as $token) {
            $like = $this->contains($token);
            $query->where(fn (Builder $w) => $w
                ->whereRaw($this->col('products.name').' LIKE '.$this->param(), [$like])
                ->orWhereIn('products.species_id', $this->speciesMatch(Species::query(), $token)->select('species.id'))
                ->orWhereIn('products.company_id', $this->companyMatch(Company::query(), $token)->select('companies.id')));
        }

        return $query
            ->orderByRaw($this->prefixRank('products.name'), $this->prefixBindings($q))
            ->orderByDesc('products.is_featured')
            ->orderByDesc('products.is_best_seller')
            ->orderByDesc('products.created_at')
            ->limit($limit)
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'name' => $p->name,
                'species' => $p->getAttribute('species_name'),
                'company_name' => $p->getAttribute('company_name'),
                'image_url' => $p->primaryImageUrl(),
                'price_label' => $p->priceLabel(),
                'url' => route('products.show', $p->slug),
            ])
            ->values()
            ->all();
    }

    /** @param list<string> $tokens @return list<array<string, mixed>> */
    private function suppliers(array $tokens, string $q, int $limit): array
    {
        $query = Company::publiclyVisible()->select(['companies.id', 'slug', 'legal_name', 'trade_name', 'logo_path', 'city', 'region', 'verified_at', 'status']);

        foreach ($tokens as $token) {
            $this->companyMatch($query, $token);
        }

        return $query
            ->orderByRaw($this->prefixRank('legal_name'), $this->prefixBindings($q))
            ->orderByDesc('is_featured')
            ->orderBy('legal_name')
            ->limit($limit)
            ->get()
            ->map(fn (Company $c) => [
                'slug' => $c->slug,
                'name' => $c->trade_name ?: $c->legal_name,
                'logo_url' => $c->logoUrl(),
                'city' => $c->city ?: $c->region,
                // publiclyVisible() already requires verified status + an active badge.
                'verified' => true,
                'url' => route('companies.show', $c->slug),
            ])
            ->values()
            ->all();
    }

    /** @param list<string> $tokens @return list<array<string, mixed>> */
    private function species(array $tokens, string $q, int $limit): array
    {
        $query = Species::published()->select(['id', 'slug', 'common_name', 'scientific_name', 'french_name']);

        foreach ($tokens as $token) {
            $this->speciesMatch($query, $token);
        }

        return $query
            ->orderByRaw($this->prefixRank('common_name'), $this->prefixBindings($q))
            ->orderBy('common_name')
            ->limit($limit)
            ->get()
            ->map(fn (Species $s) => [
                'slug' => $s->slug,
                'name' => $s->common_name,
                'scientific_name' => $s->scientific_name,
                'url' => route('species.show', $s->slug),
            ])
            ->values()
            ->all();
    }

    private function speciesMatch(Builder $query, string $token): Builder
    {
        $like = $this->contains($token);

        return $query->where(fn (Builder $w) => $w
            ->whereRaw($this->col('species.common_name').' LIKE '.$this->param(), [$like])
            ->orWhereRaw($this->col('species.scientific_name').' LIKE '.$this->param(), [$like])
            ->orWhereRaw($this->col('species.french_name').' LIKE '.$this->param(), [$like])
            // Small table, no index needed: local + trade synonyms ("Bubinga" / "Kévazingo").
            ->orWhereRaw($this->col('species.local_names::text').' LIKE '.$this->param(), [$like])
            ->orWhereRaw($this->col('species.synonyms::text').' LIKE '.$this->param(), [$like]));
    }

    private function companyMatch(Builder $query, string $token): Builder
    {
        $like = $this->contains($token);

        return $query->where(fn (Builder $w) => $w
            ->whereRaw($this->col('companies.legal_name').' LIKE '.$this->param(), [$like])
            ->orWhereRaw($this->col('companies.trade_name').' LIKE '.$this->param(), [$like]));
    }

    /** Starts-with beats word-start beats substring. */
    private function prefixRank(string $column): string
    {
        $col = $this->col($column);
        $p = $this->param();

        return "CASE WHEN {$col} LIKE {$p} THEN 0 WHEN {$col} LIKE {$p} THEN 1 ELSE 2 END";
    }

    /** @return list<string> */
    private function prefixBindings(string $q): array
    {
        $escaped = $this->escape($q);

        return [$escaped.'%', '% '.$escaped.'%'];
    }

    private function contains(string $token): string
    {
        return '%'.$this->escape($token).'%';
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** Matches the expression indexes: ct_unaccent(lower(col)) or lower(col). */
    private function col(string $column): string
    {
        return $this->hasUnaccent() ? "ct_unaccent(lower({$column}))" : "lower({$column})";
    }

    private function param(): string
    {
        return $this->hasUnaccent() ? 'ct_unaccent(?)' : '?';
    }

    /** Whether the migration managed to create ct_unaccent() on this host. */
    public function hasUnaccent(): bool
    {
        return $this->unaccent ??= DB::getDriverName() === 'pgsql'
            && (bool) Cache::remember('search-suggest:has-unaccent', 3600, fn (): bool => DB::table('pg_proc')->where('proname', 'ct_unaccent')->exists());
    }
}
