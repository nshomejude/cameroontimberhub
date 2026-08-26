<?php

namespace App\Support;

use App\Models\Article;
use App\Models\SlugRedirect;

/**
 * The single implementation of "which 301s should exist for this article?".
 *
 * Two callers use it: App\Observers\ArticleObserver, which fires on any save
 * that moves an article's canonical URL, and the articles:import command, which
 * additionally claims the legacy /insights/{slug} path for a hubbed piece.
 */
class ArticleRedirects
{
    /**
     * Reconciles the redirect table with the article's current canonical URL.
     *
     * @param  string|null  $previousUrl  The canonical URL the article answered on before this save.
     * @param  bool  $includeLegacyInsights  Also claim /insights/{slug} for a hubbed article.
     */
    public static function sync(Article $article, ?string $previousUrl = null, bool $includeLegacyInsights = false): void
    {
        $canonical = $article->url();
        $canonicalPath = self::path($canonical);

        // A redirect pointing away from the URL the article now answers on
        // would 301 straight into a 404 — this happens when a piece is pulled
        // back out of a hub. Drop it before recording the current ones.
        SlugRedirect::where('from_slug', $canonicalPath)->delete();

        $stale = [];

        if ($includeLegacyInsights) {
            $stale[] = route('insights.show', $article->slug);
        }

        if ($previousUrl !== null) {
            $stale[] = $previousUrl;
        }

        foreach (array_unique($stale) as $url) {
            $path = self::path($url);

            if ($path === '' || $path === $canonicalPath) {
                continue;
            }

            SlugRedirect::record($path, $canonical, Article::class, $article->getKey());
        }

        // Redirect chains: rows recorded on an earlier move still point at the
        // URL the article has just left, which is now a 404. Re-aim them at the
        // new canonical so no URL the piece ever answered on is lost.
        if ($previousUrl !== null && $previousUrl !== $canonical) {
            SlugRedirect::where('to_url', $previousUrl)
                ->where('from_slug', '!=', $canonicalPath)
                ->update(['to_url' => $canonical]);
        }
    }

    /** The canonical URL the article answered on before the pending save. */
    public static function originalUrl(Article $article): string
    {
        $original = $article->newInstance([], true);
        $original->forceFill([
            'slug' => $article->getOriginal('slug'),
            'hub' => $article->getOriginal('hub'),
        ]);

        return $original->url();
    }

    private static function path(string $url): string
    {
        return ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
