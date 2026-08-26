<?php

namespace App\Http\Controllers\Public;

use App\Enums\ArticleCategory;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Company;
use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Species;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * AI/answer-engine user agents we explicitly welcome in robots.txt.
     *
     * @var list<string>
     */
    public const AI_CRAWLERS = [
        'GPTBot',
        'OAI-SearchBot',
        'ChatGPT-User',
        'ClaudeBot',
        'Claude-User',
        'Claude-SearchBot',
        'anthropic-ai',
        'PerplexityBot',
        'Perplexity-User',
        'Google-Extended',
        'CCBot',
        'Applebot-Extended',
        'cohere-ai',
        'Meta-ExternalAgent',
    ];

    /**
     * The single source of truth for the site's key landing pages, consumed by
     * BOTH sitemap.xml and /llms.txt so the two can never drift apart. Each row
     * carries the URL plus the metadata each renderer needs: a sitemap priority
     * and a human/LLM-facing label.
     *
     * @return list<array{loc: string, priority: string, label: string}>
     */
    private function keyPages(): array
    {
        return [
            ['loc' => route('home'), 'priority' => '1.0', 'label' => 'Home'],
            ['loc' => route('directory'), 'priority' => '0.9', 'label' => 'Supplier directory — verified Cameroon timber suppliers'],
            ['loc' => route('seo.exporters'), 'priority' => '0.9', 'label' => 'Cameroon timber exporters'],
            ['loc' => route('seo.suppliers'), 'priority' => '0.8', 'label' => 'Cameroon timber suppliers'],
            ['loc' => route('species.index'), 'priority' => '0.8', 'label' => 'Timber species directory'],
            ['loc' => route('marketplace'), 'priority' => '0.9', 'label' => 'Product marketplace'],
            ['loc' => route('rfq.create'), 'priority' => '0.7', 'label' => 'Request a quote (RFQ)'],
            ['loc' => route('insights.index'), 'priority' => '0.9', 'label' => 'Insights — guides, market and compliance articles'],
            ['loc' => route('glossary.index'), 'priority' => '0.7', 'label' => 'Timber glossary — Cameroon timber trade terms defined'],
            ['loc' => route('pricing'), 'priority' => '0.7', 'label' => 'Pricing'],
            ['loc' => route('about'), 'priority' => '0.5', 'label' => 'About'],
            ['loc' => route('contact'), 'priority' => '0.5', 'label' => 'Contact'],
        ];
    }

    public function index(): Response
    {
        $urls = array_map(
            fn (array $page) => ['loc' => $page['loc'], 'priority' => $page['priority']],
            $this->keyPages(),
        );

        // Editorial category views.
        foreach (ArticleCategory::cases() as $category) {
            $urls[] = ['loc' => route('insights.category', $category->value), 'priority' => '0.6'];
        }

        // Articles — published only, through the same gate the public site uses.
        foreach (Article::published()->get(['slug', 'hub', 'updated_at', 'published_at']) as $article) {
            $urls[] = [
                'loc' => $article->url(),
                'lastmod' => optional($article->updated_at ?? $article->published_at)->toAtomString(),
                'priority' => '0.8',
            ];
        }

        // Glossary terms — published only, through the same gate the public site uses.
        foreach (GlossaryTerm::published()->get(['slug', 'updated_at']) as $term) {
            $urls[] = [
                'loc' => route('glossary.show', $term->slug),
                'lastmod' => optional($term->updated_at)->toAtomString(),
                'priority' => '0.5',
            ];
        }

        // CMS pages (published only — exclude landing aliases which are already listed above).
        $excludedSlugs = ['timber-exporters-cameroon', 'cameroon-timber-suppliers', 'about'];
        foreach (Page::where('is_published', true)->get(['slug', 'updated_at']) as $page) {
            if (in_array($page->slug, $excludedSlugs, true)) {
                continue;
            }
            $urls[] = [
                'loc' => url('/'.$page->slug),
                'lastmod' => optional($page->updated_at)->toAtomString(),
                'priority' => '0.6',
            ];
        }

        // Programmatic-SEO species pages (published only).
        foreach (Species::published()->get(['slug', 'updated_at']) as $species) {
            $urls[] = [
                'loc' => route('species.show', $species->slug),
                'lastmod' => optional($species->updated_at)->toAtomString(),
                'priority' => '0.8',
            ];
            // /exporters/{species}-cameroon — only include if verified companies exist.
            if (Company::publiclyVisible()->whereHas('species', fn ($q) => $q->where('slug', $species->slug))->exists()) {
                $urls[] = [
                    'loc' => route('pseo.exporters', $species->slug.'-cameroon'),
                    'lastmod' => optional($species->updated_at)->toAtomString(),
                    'priority' => '0.8',
                ];
            }
        }

        // Company profiles — only those publicly visible (the single gate).
        foreach (Company::publiclyVisible()->get(['slug', 'updated_at']) as $company) {
            $urls[] = [
                'loc' => route('companies.show', $company->slug),
                'lastmod' => optional($company->updated_at)->toAtomString(),
                'priority' => '0.7',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url['loc']).'</loc>';
            if (! empty($url['lastmod'])) {
                $xml .= '<lastmod>'.$url['lastmod'].'</lastmod>';
            }
            $xml .= '<priority>'.$url['priority'].'</priority></url>'."\n";
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * robots.txt.
     *
     * The AI answer engines are explicitly ALLOWED, not blocked: being read and
     * cited by an assistant is the point of the /insights content, and blocking
     * a crawler only means someone else's page gets quoted instead. Each agent
     * is named individually so the intent survives anyone later tightening the
     * wildcard group. /llms.txt is advertised alongside the sitemap.
     */
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /dashboard',
            'Disallow: /account',
            '',
            '# AI / answer-engine crawlers are welcome. This content is published',
            '# to be read, cited and answered from.',
        ];

        foreach (self::AI_CRAWLERS as $agent) {
            $lines[] = 'User-agent: '.$agent;
            $lines[] = 'Allow: /';
            $lines[] = '';
        }

        $lines[] = 'Sitemap: '.route('sitemap');
        $lines[] = 'LLM-Content: '.route('llms');
        $lines[] = '';

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * /llms.txt — the emerging plain-text convention for handing a language
     * model a map of a site's substantive content. Generated from real routes
     * and real published rows; it never lists a draft.
     */
    public function llms(): Response
    {
        $out = [
            '# '.config('app.name'),
            '',
            '> B2B marketplace connecting verified Cameroonian timber suppliers with international buyers. '
                .'Species reference data, a verified supplier directory, a product marketplace and an RFQ workflow, '
                .'plus long-form editorial covering grading, legality, export logistics and market conditions.',
            '',
            'Published for AI answer engines and research agents. Content may be quoted with attribution to '
                .config('app.name').' ('.url('/').').',
            '',
            '## Key pages',
            '',
        ];

        foreach ($this->keyPages() as $page) {
            $out[] = '- ['.$page['label'].']('.$page['loc'].')';
        }
        $out[] = '';

        $articles = Article::published()->orderByDesc('published_at')
            // `hub` is required: Article::url() reads it, and a partially
            // hydrated model throws on an unselected attribute.
            ->get(['slug', 'title', 'category', 'hub', 'meta_description', 'excerpt', 'published_at']);

        foreach (ArticleCategory::cases() as $category) {
            $inCategory = $articles->where('category', $category);

            if ($inCategory->isEmpty()) {
                continue;
            }

            $out[] = '## '.$category->label();
            $out[] = '';
            $out[] = $category->description();
            $out[] = '';

            foreach ($inCategory as $article) {
                $summary = trim((string) ($article->meta_description ?: $article->excerpt));
                $out[] = '- ['.str($article->title)->squish()->value().']('.$article->url().')'
                    .($summary !== '' ? ': '.str($summary)->squish()->limit(200)->value() : '');
            }
            $out[] = '';
        }

        $species = Species::published()->orderBy('common_name')->get(['slug', 'common_name', 'scientific_name']);

        if ($species->isNotEmpty()) {
            $out[] = '## Species reference';
            $out[] = '';
            foreach ($species as $sp) {
                $out[] = '- ['.str($sp->common_name)->squish()->value().']('.route('species.show', $sp->slug).')'
                    .($sp->scientific_name ? ' — '.str($sp->scientific_name)->squish()->value() : '');
            }
            $out[] = '';
        }

        $terms = GlossaryTerm::published()->orderBy('term')->get(['slug', 'term', 'definition']);

        if ($terms->isNotEmpty()) {
            $out[] = '## Glossary';
            $out[] = '';
            $out[] = 'Definitions of Cameroon timber trade terminology — grading, drying, measurement, shipping and compliance.';
            $out[] = '';
            foreach ($terms as $term) {
                $out[] = '- ['.str($term->term)->squish()->value().']('.route('glossary.show', $term->slug).')'
                    .': '.str((string) $term->definition)->squish()->limit(200)->value();
            }
            $out[] = '';
        }

        return response(implode("\n", $out), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
