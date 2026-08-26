<?php

namespace App\Support;

use App\Enums\ProductType;
use App\Models\Article;
use Illuminate\Support\Str;

/**
 * Turns an article's markdown into the HTML the public page renders, and into
 * the heading list the table of contents is built from.
 *
 * Three passes:
 *   1. Internal-link resolution. Writers reference the catalogue with opaque
 *      targets — `[Sapele](species:sapele)`, `[sawn timber](marketplace:sawn_timber)`,
 *      `[suppliers](suppliers:)`, `[post an RFQ](rfq:)` — so the markdown files
 *      never hard-code a URL that a later route change would break. An
 *      `insights:{slug}` target resolves through the article's own `url()`, so
 *      a cross-link to a piece that has moved into a Knowledge Centre hub
 *      points at the hub URL directly rather than at a 301.
 *   2. CommonMark render with `html_input => escape`. Raw HTML in a body is
 *      escaped rather than passed through, so the column can never carry a
 *      script tag onto a public page.
 *   3. Heading anchors. Every H2/H3 gets a stable, unique id so the TOC's jump
 *      links and any deep link into the piece keep working.
 */
class ArticleBody
{
    /** Markdown link targets that resolve to real internal routes. */
    private const SCHEMES = ['species', 'marketplace', 'suppliers', 'rfq', 'insights'];

    public static function render(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        $html = Str::markdown(self::resolveLinks($markdown), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return self::anchorHeadings($html);
    }

    /**
     * The article's H2s, in document order, as {id, text}. H2 only: the TOC is
     * a map of the piece's sections, and folding H3s in turns it into an
     * outline nobody reads.
     *
     * @return list<array{id: string, text: string}>
     */
    public static function headings(string $markdown): array
    {
        preg_match_all('/<h2\b[^>]*\bid="([^"]+)"[^>]*>(.*?)<\/h2>/is', self::render($markdown), $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(fn (array $m): array => [
                'id' => $m[1],
                'text' => trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            ])
            ->filter(fn (array $h): bool => $h['text'] !== '')
            ->values()
            ->all();
    }

    /** Rewrites `scheme:value` markdown link targets into absolute URLs. */
    public static function resolveLinks(string $markdown): string
    {
        $pattern = '/\]\((('.implode('|', self::SCHEMES).'):([a-z0-9_\-]*))\)/i';

        $articles = self::articleUrls($markdown);

        return (string) preg_replace_callback($pattern, function (array $m) use ($articles): string {
            $url = self::resolve(strtolower($m[2]), strtolower($m[3]), $articles);

            return $url === null ? '](#)' : ']('.$url.')';
        }, $markdown);
    }

    /**
     * Canonical URLs for every article an `insights:{slug}` link in this body
     * points at, resolved in ONE query rather than one per link — a body can
     * carry a dozen cross-links. An article that has since moved into a
     * Knowledge Centre hub lives at /knowledge/{hub}/{slug}, so resolving
     * through Article::url() keeps in-body links on the canonical URL instead
     * of leaning on the SlugRedirect 301. A slug with no article is simply
     * absent from the map and falls back to the /insights route.
     *
     * @return array<string, string>
     */
    private static function articleUrls(string $markdown): array
    {
        preg_match_all('/\]\(insights:([a-z0-9_\-]+)\)/i', $markdown, $matches);

        $slugs = array_unique(array_map('strtolower', $matches[1]));

        if ($slugs === []) {
            return [];
        }

        // Deliberately not gated by published(): this only chooses between two
        // URL shapes for a slug an author wrote by hand, and an unpublished
        // target would otherwise silently get the wrong shape rather than be
        // hidden — the article page itself is the visibility gate.
        return Article::query()
            // `hub` is required: Article::url() reads it, and a partially
            // hydrated model throws on an unselected attribute.
            ->whereIn('slug', $slugs)
            ->get(['slug', 'hub'])
            ->mapWithKeys(fn (Article $a): array => [$a->slug => $a->url()])
            ->all();
    }

    /** @param  array<string, string>  $articles */
    private static function resolve(string $scheme, string $value, array $articles = []): ?string
    {
        return match ($scheme) {
            'species' => $value === '' ? route('species.index') : route('species.show', $value),
            'suppliers' => $value === '' ? route('directory') : route('companies.show', $value),
            'rfq' => route('rfq.create', $value === '' ? [] : ['species' => $value]),
            'insights' => $value === ''
                ? route('insights.index')
                : ($articles[$value] ?? route('insights.show', $value)),
            'marketplace' => $value === '' || ProductType::tryFrom($value) === null
                ? url('/marketplace')
                : url('/marketplace').'?type='.$value,
            default => null,
        };
    }

    /** Gives every H2/H3 a unique, slugged id derived from its own text. */
    private static function anchorHeadings(string $html): string
    {
        $seen = [];

        return (string) preg_replace_callback('/<(h[23])>(.*?)<\/\1>/is', function (array $m) use (&$seen): string {
            $text = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $base = Str::slug($text) ?: 'section';

            $id = $base;
            $n = 2;
            while (isset($seen[$id])) {
                $id = $base.'-'.$n;
                $n++;
            }
            $seen[$id] = true;

            return '<'.$m[1].' id="'.e($id).'">'.$m[2].'</'.$m[1].'>';
        }, $html);
    }
}
