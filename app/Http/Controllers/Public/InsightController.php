<?php

namespace App\Http\Controllers\Public;

use App\Enums\ArticleCategory;
use App\Enums\KnowledgeHub;
use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /insights — the editorial content platform.
 *
 * Everything here reads through Article::published(), which is the single
 * public-visibility gate: drafts, archived pieces and future-dated articles are
 * unreachable from the index, from a direct slug, from search and from the
 * sitemap.
 */
class InsightController extends Controller
{
    private const PER_PAGE = 9;

    public function index(Request $request): View
    {
        $category = ArticleCategory::tryFrom((string) $request->query('category', ''));
        $query = trim((string) $request->query('q', ''));

        $articles = $this->paginate($category, $query, $request);

        $counts = Article::published()
            ->selectRaw('category, count(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category');

        $breadcrumbs = [
            ['label' => 'Home', 'url' => route('home')],
            ['label' => 'Insights', 'url' => route('insights.index')],
        ];

        if ($category) {
            $breadcrumbs[] = ['label' => $category->label(), 'url' => route('insights.category', $category->value)];
        }

        return view('public.insights.index', [
            'articles' => $articles,
            'category' => $category,
            'q' => $query,
            'counts' => $counts,
            'total' => Article::published()->count(),
            'breadcrumbs' => $breadcrumbs,
            'schema' => $this->indexSchema($articles, $category),
        ]);
    }

    /** Pretty per-category URL — this is what the footer resource links point at. */
    public function category(Request $request, string $category): View
    {
        abort_unless($enum = ArticleCategory::tryFrom($category), 404);

        $request->query->set('category', $enum->value);

        return $this->index($request);
    }

    public function show(string $slug): View
    {
        return $this->renderArticle(Article::published()->where('slug', $slug)->firstOrFail());
    }

    /**
     * An evergreen article at its Knowledge Centre URL. The hub segment is part
     * of the article's identity here: requesting a real article under the wrong
     * hub is a 404, so exactly one canonical URL resolves per piece.
     */
    public function hubArticle(string $hub, string $slug): View
    {
        abort_unless($knowledgeHub = KnowledgeHub::tryFrom($hub), 404);

        $article = Article::published()
            ->inHub($knowledgeHub)
            ->where('slug', $slug)
            ->firstOrFail();

        return $this->renderArticle($article);
    }

    /**
     * The shared article page. Both /insights/{slug} and /knowledge/{hub}/{slug}
     * render it; the trail above the title follows the article's own canonical
     * URL, so a hubbed piece reads Knowledge Centre and an unhubbed one reads
     * Insights.
     */
    private function renderArticle(Article $article): View
    {
        $related = Article::published()
            ->where('category', $article->category->value)
            ->whereKeyNot($article->getKey())
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        // Backfill with anything recent if the category is thin.
        if ($related->count() < 3) {
            $related = $related->concat(
                Article::published()
                    ->whereKeyNot($article->getKey())
                    ->whereNotIn('id', $related->modelKeys())
                    ->orderByDesc('published_at')
                    ->limit(3 - $related->count())
                    ->get()
            );
        }

        return view('public.insights.show', [
            'article' => $article,
            'related' => $related,
            'toc' => $article->tableOfContents(),
            'faqs' => $article->faqPairs(),
            'sources' => $article->sourceList(),
            'relatedSpecies' => $article->relatedSpecies(),
            'breadcrumbs' => $this->articleBreadcrumbs($article),
            'schema' => $this->articleSchema($article),
        ]);
    }

    /**
     * The trail above an article title, following its canonical URL.
     *
     * @return list<array{label: string, url: string}>
     */
    private function articleBreadcrumbs(Article $article): array
    {
        $trail = [['label' => 'Home', 'url' => route('home')]];

        if ($article->hub) {
            $trail[] = ['label' => 'Knowledge Centre', 'url' => route('knowledge.index')];
            $trail[] = ['label' => $article->hub->label(), 'url' => $article->hub->url()];
        } else {
            $trail[] = ['label' => 'Insights', 'url' => route('insights.index')];
            $trail[] = ['label' => $article->category->label(), 'url' => route('insights.category', $article->category->value)];
        }

        $trail[] = ['label' => $article->title, 'url' => $article->url()];

        return $trail;
    }

    /** @return LengthAwarePaginator<int, Article> */
    private function paginate(?ArticleCategory $category, string $query, Request $request): LengthAwarePaginator
    {
        return Article::published()
            ->when($category, fn ($q) => $q->where('category', $category->value))
            ->search($query)
            ->orderByDesc('published_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, Article>  $articles
     * @return array<string, mixed>
     */
    private function indexSchema(LengthAwarePaginator $articles, ?ArticleCategory $category): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $category ? $category->label().' — Cameroon timber insights' : 'Cameroon timber insights',
            'description' => $category?->description() ?? 'Guides, market insight and compliance explainers for buyers of Cameroonian timber.',
            'url' => url()->current(),
            'isPartOf' => ['@id' => url('/#website')],
            'publisher' => ['@id' => url('/#organization')],
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => $articles->total(),
                'itemListElement' => $articles->values()->map(fn (Article $a, int $i): array => [
                    '@type' => 'ListItem',
                    'position' => ($articles->firstItem() ?? 1) + $i,
                    'name' => $a->title,
                    'url' => $a->url(),
                ])->all(),
            ],
        ];
    }

    /**
     * Article + FAQPage graph.
     *
     * FAQPage is emitted ONLY when there are real FAQ pairs, because those same
     * pairs are what the page renders in its FAQ block. Structured answers with
     * no visible on-page match are discounted by answer engines, so the markup
     * and the DOM are driven from one method (Article::faqPairs()).
     *
     * @return array<string, mixed>
     */
    private function articleSchema(Article $article): array
    {
        $graph = [];

        $graph[] = array_filter([
            '@type' => 'Article',
            '@id' => $article->url().'#article',
            'headline' => $article->heading,
            'name' => $article->title,
            'description' => $article->meta_description ?: $article->excerpt,
            'inLanguage' => app()->getLocale(),
            'datePublished' => $article->published_at?->toAtomString(),
            'dateModified' => ($article->updated_at ?? $article->published_at)?->toAtomString(),
            'image' => $article->heroImageUrl(),
            'keywords' => $article->keywords ? implode(', ', $article->keywords) : null,
            'articleSection' => $article->category->label(),
            'wordCount' => str_word_count(strip_tags((string) $article->body)) ?: null,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $article->url()],
            'isPartOf' => ['@id' => url('/#website')],
            'publisher' => ['@id' => url('/#organization')],
            // E-E-A-T: the organisation authors by default. A Person is only
            // claimed when a real name has actually been recorded.
            'author' => $article->hasNamedAuthor()
                ? array_filter(['@type' => 'Person', 'name' => $article->author_name, 'jobTitle' => $article->author_role])
                : ['@id' => url('/#organization')],
        ], fn ($v): bool => $v !== null && $v !== []);

        if ($faqs = $article->faqPairs()) {
            $graph[] = [
                '@type' => 'FAQPage',
                '@id' => $article->url().'#faq',
                'mainEntity' => array_map(fn (array $faq): array => [
                    '@type' => 'Question',
                    'name' => $faq['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']],
                ], $faqs),
            ];
        }

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }
}
