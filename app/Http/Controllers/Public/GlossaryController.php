<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * /knowledge/glossary — the Cameroon timber glossary.
 *
 * Every term reads through GlossaryTerm::published(), the single visibility
 * gate: an unpublished term is unreachable from the index, from a direct
 * slug, from search, from the sitemap and from /llms.txt.
 */
class GlossaryController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $terms = GlossaryTerm::published()
            ->search($q)
            ->orderBy('term')
            ->get()
            ->groupBy(fn (GlossaryTerm $t): string => strtoupper(mb_substr($t->term, 0, 1)));

        return view('public.glossary.index', [
            'terms' => $terms,
            'q' => $q,
            'total' => GlossaryTerm::published()->count(),
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Glossary', 'url' => route('glossary.index')],
            ],
        ]);
    }

    public function show(string $slug): View
    {
        $term = GlossaryTerm::published()->where('slug', $slug)->firstOrFail();

        return view('public.glossary.show', [
            'term' => $term,
            'relatedTerms' => $term->relatedTerms(),
            'relatedSpecies' => $term->relatedSpecies(),
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Glossary', 'url' => route('glossary.index')],
                ['label' => $term->term, 'url' => $term->url()],
            ],
            'schema' => $this->termSchema($term),
        ]);
    }

    /** @return array<string, mixed> */
    private function termSchema(GlossaryTerm $term): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'DefinedTerm',
            '@id' => $term->url().'#term',
            'name' => $term->term,
            'description' => $term->definition,
            'inDefinedTermSet' => [
                '@type' => 'DefinedTermSet',
                '@id' => route('glossary.index').'#glossary',
                'name' => config('app.name').' Glossary',
                'url' => route('glossary.index'),
            ],
            'url' => $term->url(),
        ];
    }
}
