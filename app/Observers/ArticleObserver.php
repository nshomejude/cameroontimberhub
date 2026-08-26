<?php

namespace App\Observers;

use App\Models\Article;
use App\Support\ArticleBody;
use App\Support\ArticleRedirects;

/**
 * Keeps an article's slug redirects honest no matter where the change came
 * from — the importer, the Filament form, a seeder or a plain model save.
 *
 * Moving a piece between Knowledge Centre hubs rewrites its canonical URL, and
 * the URL it just left would otherwise become a hard 404: InsightController
 * only rescues the legacy /insights/{slug} shape, never /knowledge/{old-hub}/…
 * So the previous canonical path is recorded as a 301 here, structurally,
 * rather than in whichever code path happened to make the change.
 */
class ArticleObserver
{
    /**
     * Canonical URLs captured before the write, keyed by the model instance.
     * Populated in `updating` because `saved` runs after syncOriginal(), by
     * which point the pre-save hub and slug are gone. A WeakMap rather than an
     * spl_object_id array: if a save throws between the two events the entry
     * dies with the model instead of lingering under a recycled object id.
     *
     * @var \WeakMap<Article, string>
     */
    private static ?\WeakMap $previousUrls = null;

    public function updating(Article $article): void
    {
        if (! $article->isDirty(['hub', 'slug'])) {
            return;
        }

        self::$previousUrls ??= new \WeakMap;
        self::$previousUrls[$article] = ArticleRedirects::originalUrl($article);
    }

    public function saved(Article $article): void
    {
        $previousUrl = null;

        if (self::$previousUrls?->offsetExists($article)) {
            $previousUrl = self::$previousUrls[$article];
            unset(self::$previousUrls[$article]);
        }

        // Nothing that affects a URL moved, so the redirect table is already
        // consistent — leave it alone rather than churning writes on every save.
        if ($previousUrl === null && ! $article->wasRecentlyCreated) {
            return;
        }

        ArticleRedirects::sync($article, $previousUrl);

        // An `insights:{slug}` cross-link in any body resolves through
        // Article::url(), so a hub or slug move invalidates memoised HTML.
        ArticleBody::flushMemo();
    }
}
