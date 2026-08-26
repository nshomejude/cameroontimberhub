<?php

namespace App\Console\Commands;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Species;
use App\Support\Frontmatter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Imports `content/articles/*.md` (YAML frontmatter + markdown body) into the
 * `articles` table.
 *
 * OVERWRITE RULE — the whole point of the command is that it is safe to re-run
 * without silently reverting an editor's work:
 *
 *   • slug not in the database          → CREATE.
 *   • file unchanged since last import  → SKIP (idempotent no-op). "Unchanged"
 *     means the file's mtime is not newer than the row's `source_synced_at`.
 *   • file changed, row untouched in    → UPDATE.
 *     the admin since last import
 *   • file changed, but the row was     → SKIP and report a conflict. The admin
 *     edited in the admin since the       edit is the newer human decision, so
 *     last import                         it is never clobbered blindly.
 *   • --force                           → UPDATE regardless, discarding admin
 *                                         edits for the fields the file sets.
 *
 * An admin edit is detected by `updated_at > source_synced_at`: every import
 * stamps both together, so any later save moves `updated_at` ahead on its own.
 */
class ImportArticles extends Command
{
    protected $signature = 'articles:import
        {--path= : Directory of markdown files (default: content/articles)}
        {--force : Overwrite rows that were edited in the admin since the last import}
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Import editorial articles from content/articles/*.md (idempotent).';

    /** Frontmatter keys the importer understands. Anything else is reported. */
    private const KNOWN_KEYS = [
        'title', 'slug', 'h1', 'excerpt', 'category', 'status', 'published_at',
        'meta_title', 'meta_description', 'keywords', 'faqs', 'sources',
        'author', 'author_name', 'author_role', 'hero_image', 'reading_minutes',
        'related_species', 'related_product_types',
    ];

    public function handle(): int
    {
        $dir = rtrim((string) ($this->option('path') ?: base_path('content/articles')), '/\\');

        if (! is_dir($dir)) {
            $this->components->error("No such directory: {$dir}");

            return self::FAILURE;
        }

        $files = collect(glob($dir.'/*.md') ?: [])
            ->reject(fn (string $f): bool => str_starts_with(basename($f), '_'))
            ->reject(fn (string $f): bool => strtolower(basename($f)) === 'readme.md')
            ->sort()
            ->values();

        if ($files->isEmpty()) {
            $this->components->info("No markdown files in {$dir}.");

            return self::SUCCESS;
        }

        $tally = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'conflict' => 0, 'failed' => 0];
        $dryRun = (bool) $this->option('dry-run');

        foreach ($files as $file) {
            try {
                $outcome = $this->importFile($file, $dryRun);
            } catch (\Throwable $e) {
                $this->components->error(basename($file).': '.$e->getMessage());
                $tally['failed']++;

                continue;
            }

            $tally[$outcome[0]]++;
            $this->components->twoColumnDetail(basename($file), $outcome[1]);
        }

        $this->newLine();
        $this->components->info(sprintf(
            '%s%d created, %d updated, %d unchanged, %d conflicts, %d failed.',
            $dryRun ? '[dry run] ' : '',
            $tally['created'], $tally['updated'], $tally['skipped'], $tally['conflict'], $tally['failed']
        ));

        return $tally['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: 'created'|'updated'|'skipped'|'conflict', 1: string}
     */
    private function importFile(string $file, bool $dryRun): array
    {
        [$frontmatter, $body] = Frontmatter::split((string) file_get_contents($file));

        $slug = Str::slug((string) ($frontmatter['slug'] ?? pathinfo($file, PATHINFO_FILENAME)));

        if ($slug === '') {
            throw new \RuntimeException('could not determine a slug');
        }

        if (blank($frontmatter['title'] ?? null)) {
            throw new \RuntimeException('frontmatter is missing the required `title`');
        }

        $category = ArticleCategory::tryFrom((string) ($frontmatter['category'] ?? ''));

        if (! $category) {
            throw new \RuntimeException(sprintf(
                'unknown category "%s" (expected one of: %s)',
                $frontmatter['category'] ?? '',
                implode(', ', ArticleCategory::values())
            ));
        }

        if ($unknown = array_diff(array_keys($frontmatter), self::KNOWN_KEYS)) {
            $this->components->warn(basename($file).': ignoring unknown frontmatter key(s): '.implode(', ', $unknown));
        }

        $mtime = filemtime($file) ?: time();
        $fileTime = CarbonImmutable::createFromTimestamp($mtime);
        $existing = Article::where('slug', $slug)->first();

        if ($existing && ! $this->option('force')) {
            // "Has the file changed?" — raw mtime against raw mtime.
            if ($existing->source_mtime !== null && $mtime <= $existing->source_mtime) {
                return ['skipped', 'unchanged'];
            }

            // "Has a human saved since the last import?" — both sides are
            // database timestamps, so this comparison is well-defined.
            $synced = $existing->source_synced_at;

            if ($synced && $existing->updated_at && $existing->updated_at->greaterThan($synced->addSecond())) {
                return ['conflict', 'edited in the admin since last import — skipped (use --force to overwrite)'];
            }
        }

        $attributes = $this->attributes($frontmatter, $body, $category, $fileTime) + ['source_mtime' => $mtime];

        if ($dryRun) {
            return $existing ? ['updated', 'would update'] : ['created', 'would create'];
        }

        Article::updateOrCreate(['slug' => $slug], $attributes);

        return $existing ? ['updated', 'updated'] : ['created', 'created'];
    }

    /**
     * @param  array<string, mixed>  $fm
     * @return array<string, mixed>
     */
    private function attributes(array $fm, string $body, ArticleCategory $category, CarbonImmutable $fileTime): array
    {
        $status = ArticleStatus::tryFrom((string) ($fm['status'] ?? 'published')) ?? ArticleStatus::Published;

        $publishedAt = filled($fm['published_at'] ?? null)
            ? CarbonImmutable::parse((string) $fm['published_at'])
            : null;

        // The published-at CHECK constraint is real: a published article without
        // a date would be rejected by the database, so default it to the file.
        if ($status === ArticleStatus::Published && ! $publishedAt) {
            $publishedAt = $fileTime;
        }

        // E-E-A-T: `author` may be a plain string or {name, role}. When it is
        // absent the byline falls back to the organisation at render time —
        // this importer never invents a person.
        $author = $fm['author'] ?? null;
        $authorName = $fm['author_name'] ?? (is_array($author) ? ($author['name'] ?? null) : $author);
        $authorRole = $fm['author_role'] ?? (is_array($author) ? ($author['role'] ?? null) : null);

        if (is_string($authorName) && in_array(Str::lower(trim($authorName)), ['', 'editorial', 'cameroon timber hub editorial'], true)) {
            $authorName = null;
        }

        return [
            'title' => (string) $fm['title'],
            'h1' => $fm['h1'] ?? null,
            'excerpt' => $fm['excerpt'] ?? null,
            'body' => $body,
            'category' => $category,
            'status' => $status,
            'published_at' => $publishedAt,
            'meta_title' => $fm['meta_title'] ?? null,
            'meta_description' => $fm['meta_description'] ?? null,
            'keywords' => $this->stringList($fm['keywords'] ?? null),
            'faqs' => $this->pairs($fm['faqs'] ?? null, 'question', 'answer'),
            'sources' => $this->pairs($fm['sources'] ?? null, 'url', 'label'),
            'hero_image_path' => $fm['hero_image'] ?? null,
            'reading_minutes' => isset($fm['reading_minutes'])
                ? (int) $fm['reading_minutes']
                : Article::estimateReadingMinutes($body),
            'related_species_ids' => $this->speciesIds($fm['related_species'] ?? null),
            'related_product_types' => $this->stringList($fm['related_product_types'] ?? null),
            // Stamped with the import moment, not the file's mtime: it is the
            // watermark both halves of the overwrite rule compare against —
            // "has the file changed since?" and "has a human saved since?".
            'source_synced_at' => now(),
        ];
    }

    /** @return list<string>|null */
    private function stringList(mixed $value): ?array
    {
        if (blank($value)) {
            return null;
        }

        $items = is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value);

        $items = array_values(array_filter(array_map(
            fn ($v): string => trim((string) $v),
            $items ?: []
        ), fn (string $v): bool => $v !== ''));

        return $items ?: null;
    }

    /**
     * Normalises a list of two-key maps (FAQs, sources). Accepts either
     * `{question:, answer:}` style keys or a single-pair mapping.
     *
     * @return list<array<string, string>>|null
     */
    private function pairs(mixed $value, string $keyA, string $keyB): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $rows = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $a = trim((string) ($row[$keyA] ?? ''));
            $b = trim((string) ($row[$keyB] ?? ''));

            if ($a === '') {
                continue;
            }

            $rows[] = [$keyA => $a, $keyB => $b];
        }

        return $rows ?: null;
    }

    /**
     * Resolves `related_species` slugs (or ids) to real species ids. Unknown
     * slugs are reported and dropped rather than written as dangling ids.
     *
     * @return list<int>|null
     */
    private function speciesIds(mixed $value): ?array
    {
        $wanted = $this->stringList($value);

        if (! $wanted) {
            return null;
        }

        $bySlug = Species::whereIn('slug', $wanted)->pluck('id', 'slug');

        $ids = [];

        foreach ($wanted as $key) {
            if (isset($bySlug[$key])) {
                $ids[] = (int) $bySlug[$key];
            } elseif (ctype_digit($key) && Species::whereKey((int) $key)->exists()) {
                $ids[] = (int) $key;
            } else {
                $this->components->warn("related_species: no species matches \"{$key}\" — dropped.");
            }
        }

        return array_values(array_unique($ids)) ?: null;
    }
}
