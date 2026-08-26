<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\GlossaryTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $total = GlossaryTerm::published()->count();

        $terms = GlossaryTerm::published()
            ->search($q)
            ->orderBy('term')
            // The index renders only the link (slug), the heading letter (term)
            // and a 140-char truncation of the definition — never the
            // paragraph-length explanation or the cross-link arrays.
            ->get(['id', 'slug', 'term', 'definition'])
            ->groupBy(fn (GlossaryTerm $t): string => strtoupper(mb_substr($t->term, 0, 1)));

        return view('public.glossary.index', [
            'terms' => $terms,
            'q' => $q,
            'total' => $total,
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Glossary', 'url' => route('glossary.index')],
            ],
            'schema' => $this->indexSchema($terms),
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

    /**
     * The DefinedTermSet node that every term page's `inDefinedTermSet`
     * points at. The `@id` here MUST stay identical to the one built in
     * termSchema(), otherwise each term references a node that is never
     * defined and answer engines discount the whole set.
     */
    public static function definedTermSetId(): string
    {
        return route('glossary.index').'#glossary';
    }

    /**
     * @param  Collection<string, Collection<int, GlossaryTerm>>  $terms
     * @return array<string, mixed>
     */
    private function indexSchema(Collection $terms): array
    {
        $flat = $terms->flatten();

        return [
            '@context' => 'https://schema.org',
            // Both types: it is the collection page for the glossary AND the
            // term set itself, so one node carries the @id the terms cite.
            '@type' => ['CollectionPage', 'DefinedTermSet'],
            '@id' => self::definedTermSetId(),
            'name' => config('app.name').' Glossary',
            'description' => 'Definitions for Cameroon timber trade terms — grading, shipping, compliance and species terminology explained.',
            'url' => route('glossary.index'),
            'isPartOf' => ['@id' => url('/#website')],
            'publisher' => ['@id' => url('/#organization')],
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => $flat->count(),
                'itemListElement' => $flat->values()->map(fn (GlossaryTerm $t, int $i): array => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $t->term,
                    'url' => $t->url(),
                ])->all(),
            ],
        ];
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
                '@id' => self::definedTermSetId(),
                'name' => config('app.name').' Glossary',
                'url' => route('glossary.index'),
            ],
            'url' => $term->url(),
        ];
    }
}
