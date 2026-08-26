<?php

namespace App\Models;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Enums\KnowledgeHub;
use App\Models\Concerns\HasSlug;
use App\Support\ArticleBody;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * An editorial article on /insights.
 *
 * `body` is markdown (see the create migration for why). It is rendered on read
 * through App\Support\ArticleBody, which escapes raw HTML, adds stable heading
 * anchors for the table of contents, and rewrites `species:`/`marketplace:`
 * link targets into real internal routes.
 */
class Article extends Model
{
    use HasFactory, HasSlug;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => ArticleCategory::class,
            'status' => ArticleStatus::class,
            'hub' => KnowledgeHub::class,
            'keywords' => 'array',
            'faqs' => 'array',
            'sources' => 'array',
            'related_species_ids' => 'array',
            'related_product_types' => 'array',
            'published_at' => 'datetime',
            'source_synced_at' => 'datetime',
            'source_mtime' => 'integer',
            'reading_minutes' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function slugSourceColumn(): string
    {
        return 'title';
    }

    /**
     * The single public-visibility gate. Draft and archived articles are never
     * readable from the public site, the sitemap or /llms.txt, and an article
     * scheduled for the future stays invisible until its moment arrives.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ArticleStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Evergreen Knowledge Centre content in one hub. An article with no hub is
     * short-form news and is never in any hub.
     */
    public function scopeInHub(Builder $query, KnowledgeHub $hub): Builder
    {
        return $query->where('hub', $hub->value);
    }

    public function isPublished(): bool
    {
        return $this->status === ArticleStatus::Published
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }

    /** Full-text + trigram search across title, excerpt and body. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$term])
                ->orWhere('title', 'ilike', '%'.$term.'%')
                ->orWhere('excerpt', 'ilike', '%'.$term.'%');
        });
    }

    protected function heading(): Attribute
    {
        return Attribute::get(fn (): string => $this->h1 ?: $this->title);
    }

    /** Byline. Defaults to the organisation — never an invented person. */
    protected function byline(): Attribute
    {
        return Attribute::get(fn (): string => $this->author_name ?: config('app.name').' editorial');
    }

    public function hasNamedAuthor(): bool
    {
        return filled($this->author_name);
    }

    public function heroImageUrl(): ?string
    {
        if (blank($this->hero_image_path)) {
            return null;
        }

        $path = $this->hero_image_path;

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return Str::startsWith($path, '/') ? url($path) : url('/storage/'.ltrim($path, '/'));
    }

    /** Rendered, sanitised body HTML with heading anchors and internal links. */
    public function renderedBody(): string
    {
        return ArticleBody::render((string) $this->body);
    }

    /**
     * Table of contents built from the body's H2s.
     *
     * @return list<array{id: string, text: string}>
     */
    public function tableOfContents(): array
    {
        return ArticleBody::headings((string) $this->body);
    }

    /**
     * FAQ pairs, normalised and stripped of incomplete rows. The FAQPage
     * JSON-LD and the on-page block read from this single method so the two can
     * never disagree — emitting FAQPage markup with no visible answer is
     * exactly what answer engines discount.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function faqPairs(): array
    {
        return collect($this->faqs ?? [])
            ->map(fn ($row): array => [
                'question' => trim((string) (is_array($row) ? ($row['question'] ?? '') : '')),
                'answer' => trim((string) (is_array($row) ? ($row['answer'] ?? '') : '')),
            ])
            ->filter(fn (array $row): bool => $row['question'] !== '' && $row['answer'] !== '')
            ->values()
            ->all();
    }

    /**
     * Citations, normalised to url + label.
     *
     * @return list<array{url: string, label: string}>
     */
    public function sourceList(): array
    {
        return collect($this->sources ?? [])
            ->map(function ($row): array {
                $url = trim((string) (is_array($row) ? ($row['url'] ?? '') : ''));
                $label = trim((string) (is_array($row) ? ($row['label'] ?? '') : ''));

                return ['url' => $url, 'label' => $label !== '' ? $label : $url];
            })
            ->filter(fn (array $row): bool => Str::startsWith($row['url'], ['http://', 'https://']))
            ->values()
            ->all();
    }

    /** Published species this article points into, in the order given. */
    public function relatedSpecies(): Collection
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($this->related_species_ids ?? []))));

        if ($ids === []) {
            return collect();
        }

        return Species::published()->whereIn('id', $ids)->get()
            ->sortBy(fn (Species $s): int => array_search($s->getKey(), $ids, true) ?: 0)
            ->values();
    }

    /** Estimated reading time in minutes, falling back to a word count. */
    public function readingTime(): int
    {
        if ($this->reading_minutes) {
            return max(1, (int) $this->reading_minutes);
        }

        return static::estimateReadingMinutes((string) $this->body);
    }

    public static function estimateReadingMinutes(string $markdown): int
    {
        $words = str_word_count(strip_tags($markdown));

        return max(1, (int) ceil($words / 220));
    }

    /**
     * The article's canonical public URL. A hubbed article lives in the
     * Knowledge Centre; an unhubbed one stays short-form news on /insights.
     *
     * Note for callers that column-scope their query: this reads `hub`, so a
     * partially-hydrated Article must include `hub` in its select list.
     */
    public function url(): string
    {
        return $this->hub
            ? route('knowledge.article', ['hub' => $this->hub->value, 'slug' => $this->slug])
            : route('insights.show', $this->slug);
    }
}
