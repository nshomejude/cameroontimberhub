<?php

namespace App\Http\Controllers\Public;

use App\Enums\ArticleCategory;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Company;
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

    public function index(): Response
    {
        $urls = [
            ['loc' => route('home'), 'priority' => '1.0'],
            ['loc' => route('directory'), 'priority' => '0.9'],
            ['loc' => route('seo.exporters'), 'priority' => '0.9'],
            ['loc' => route('seo.suppliers'), 'priority' => '0.8'],
            ['loc' => route('species.index'), 'priority' => '0.8'],
            ['loc' => route('pricing'), 'priority' => '0.7'],
            ['loc' => route('insights.index'), 'priority' => '0.9'],
        ];

        // Editorial category views.
        foreach (ArticleCategory::cases() as $category) {
            $urls[] = ['loc' => route('insights.category', $category->value), 'priority' => '0.6'];
        }

        // Articles — published only, through the same gate the public site uses.
        foreach (Article::published()->get(['slug', 'updated_at', 'published_at']) as $article) {
            $urls[] = [
                'loc' => route('insights.show', $article->slug),
                'lastmod' => optional($article->updated_at ?? $article->published_at)->toAtomString(),
                'priority' => '0.8',
            ];
        }

        // CMS pages (published only — exclude landing aliases which are already listed above).
        $excludedSlugs = ['timber-exporters-cameroon', 'cameroon-timber-suppliers'];
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

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain']);
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

        foreach ([
            'Home' => route('home'),
            'Supplier directory — verified Cameroon timber suppliers' => route('directory'),
            'Timber species directory' => route('species.index'),
            'Product marketplace' => url('/marketplace'),
            'Request a quote (RFQ)' => route('rfq.create'),
            'Insights — guides, market and compliance articles' => route('insights.index'),
            'Pricing' => route('pricing'),
            'About' => route('about'),
            'Contact' => route('contact'),
        ] as $label => $url) {
            $out[] = '- ['.$label.']('.$url.')';
        }
        $out[] = '';

        $articles = Article::published()->orderByDesc('published_at')->get();

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
                $out[] = '- ['.$article->title.']('.$article->url().')'
                    .($summary !== '' ? ': '.str($summary)->squish()->limit(200)->value() : '');
            }
            $out[] = '';
        }

        $species = Species::published()->orderBy('common_name')->get(['slug', 'common_name', 'scientific_name']);

        if ($species->isNotEmpty()) {
            $out[] = '## Species reference';
            $out[] = '';
            foreach ($species as $sp) {
                $out[] = '- ['.$sp->common_name.']('.route('species.show', $sp->slug).')'
                    .($sp->scientific_name ? ' — '.$sp->scientific_name : '');
            }
            $out[] = '';
        }

        return response(implode("\n", $out), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
