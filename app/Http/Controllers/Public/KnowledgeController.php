<?php

namespace App\Http\Controllers\Public;

use App\Enums\KnowledgeHub;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Support\ArticleBody;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * The Knowledge Centre — /knowledge and its eleven hubs (spec §B, §E).
 *
 * A hub is a pillar page: optional authored intro copy from
 * content/knowledge/{hub}.md, plus the published articles assigned to it. When
 * no pillar file exists the page renders its listing and description only —
 * it never invents placeholder pillar prose.
 *
 * Every listing reads through Article::published(), the single visibility gate
 * shared with /insights, search, the sitemap and /llms.txt.
 */
class KnowledgeController extends Controller
{
    public function index(): View
    {
        $counts = Article::published()
            ->whereNotNull('hub')
            ->selectRaw('hub, count(*) as aggregate')
            ->groupBy('hub')
            ->pluck('aggregate', 'hub');

        return view('public.knowledge.index', [
            'hubs' => KnowledgeHub::cases(),
            'counts' => $counts,
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Knowledge Centre', 'url' => route('knowledge.index')],
            ],
            'schema' => $this->indexSchema(),
        ]);
    }

    public function hub(string $hub): View
    {
        abort_unless($knowledgeHub = KnowledgeHub::tryFrom($hub), 404);

        $articles = Article::published()
            ->inHub($knowledgeHub)
            ->orderByDesc('published_at')
            ->get();

        return view('public.knowledge.hub', [
            'hub' => $knowledgeHub,
            'articles' => $articles,
            'pillar' => $this->pillarBody($knowledgeHub),
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Knowledge Centre', 'url' => route('knowledge.index')],
                ['label' => $knowledgeHub->label(), 'url' => $knowledgeHub->url()],
            ],
            'schema' => $this->hubSchema($knowledgeHub, $articles),
        ]);
    }

    /**
     * Rendered pillar copy for a hub, or null when none has been written yet.
     */
    private function pillarBody(KnowledgeHub $hub): ?string
    {
        $path = base_path("content/knowledge/{$hub->value}.md");

        if (! File::exists($path)) {
            return null;
        }

        $html = ArticleBody::render(File::get($path));

        return $html === '' ? null : $html;
    }

    /** @return array<string, mixed> */
    private function indexSchema(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            '@id' => route('knowledge.index').'#knowledge',
            'name' => config('app.name').' Knowledge Centre',
            'description' => 'The Cameroon timber knowledge base — fundamentals, products, processing, grading, buying, export, compliance, sustainability, logistics and the business of the trade.',
            'url' => route('knowledge.index'),
            'isPartOf' => ['@id' => url('/#website')],
            'publisher' => ['@id' => url('/#organization')],
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => count(KnowledgeHub::cases()),
                'itemListElement' => collect(KnowledgeHub::cases())
                    ->map(fn (KnowledgeHub $h, int $i): array => [
                        '@type' => 'ListItem',
                        'position' => $i + 1,
                        'name' => $h->label(),
                        'url' => $h->url(),
                    ])->all(),
            ],
        ];
    }

    /**
     * @param  Collection<int, Article>  $articles
     * @return array<string, mixed>
     */
    private function hubSchema(KnowledgeHub $hub, Collection $articles): array
    {
        $items = $articles->values()->map(fn (Article $a, int $i): array => [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => $a->title,
            'url' => $a->url(),
        ])->all();

        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            '@id' => $hub->url().'#hub',
            'name' => $hub->label(),
            'description' => $hub->description(),
            'url' => $hub->url(),
            'isPartOf' => ['@id' => url('/#website')],
            'publisher' => ['@id' => url('/#organization')],
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => count($items),
                'itemListElement' => $items,
            ],
        ];
    }
}
