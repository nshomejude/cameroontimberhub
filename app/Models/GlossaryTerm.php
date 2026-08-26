<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One term in the public Cameroon timber glossary (/knowledge/glossary).
 *
 * Deliberately flat and simple — this is a dictionary, not an article. Every
 * term links out to real related terms/species via id arrays, resolved at
 * render time, exactly like Article::relatedSpecies().
 */
class GlossaryTerm extends Model
{
    use HasFactory, HasSlug;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'related_term_ids' => 'array',
            'related_species_ids' => 'array',
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function slugSourceColumn(): string
    {
        return 'term';
    }

    /**
     * The single public-visibility gate. An unpublished term is unreachable
     * from the index, from a direct slug, from search, from the sitemap and
     * from /llms.txt — every one of those surfaces reads through this scope.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Weighted full-text search plus a trigram fallback for near-misses and
     * typos. The trigram arm uses the indexable `%` operator — never
     * `similarity(col, ?) > x`, which cannot use the GIN trigram index.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$term])
                ->orWhereRaw('term % ?', [$term]);
        });
    }

    /** @return Collection<int, GlossaryTerm> */
    public function relatedTerms(): Collection
    {
        $ids = $this->normaliseIds($this->related_term_ids);

        if ($ids === []) {
            return new Collection;
        }

        return static::published()->whereIn('id', $ids)->orderBy('term')->get();
    }

    /** @return Collection<int, Species> */
    public function relatedSpecies(): Collection
    {
        $ids = $this->normaliseIds($this->related_species_ids);

        if ($ids === []) {
            return new Collection;
        }

        return Species::published()->whereIn('id', $ids)->orderBy('common_name')->get();
    }

    /**
     * @param  mixed  $ids
     * @return list<int>
     */
    private function normaliseIds($ids): array
    {
        return array_values(array_filter(array_map('intval', (array) ($ids ?? []))));
    }

    public function url(): string
    {
        return route('glossary.show', $this->slug);
    }
}
