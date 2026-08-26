<?php

namespace App\Enums;

/**
 * The eleven Knowledge Centre hubs from the SEO authority spec §B.
 *
 * Each case is a URL segment under /knowledge and a pillar page. This enum is
 * the single source of truth: routes, navigation, the sitemap, llms.txt and
 * the Filament article form all read it, so a hub cannot exist in one surface
 * and be missing from another.
 *
 * Distinct from ArticleCategory, which describes what a piece of writing IS
 * (guide, market note, regulation explainer). A hub describes where evergreen
 * content LIVES. An article with no hub is short-form news at /insights.
 */
enum KnowledgeHub: string
{
    case Fundamentals = 'fundamentals';
    case Cameroon101 = 'cameroon-101';
    case Products = 'products';
    case Processing = 'processing';
    case Grading = 'grading';
    case Buying = 'buying';
    case Export = 'export';
    case Compliance = 'compliance';
    case Sustainability = 'sustainability';
    case Logistics = 'logistics';
    case Business = 'business';

    public function label(): string
    {
        return match ($this) {
            self::Fundamentals => 'Timber Fundamentals',
            self::Cameroon101 => 'Cameroon Timber 101',
            self::Products => 'Products Academy',
            self::Processing => 'Processing Academy',
            self::Grading => 'Quality & Grading Academy',
            self::Buying => 'Buyer Academy',
            self::Export => 'Export Academy',
            self::Compliance => 'Compliance Academy',
            self::Sustainability => 'Sustainability Academy',
            self::Logistics => 'Logistics Academy',
            self::Business => 'Business Academy',
        };
    }

    /** One-line description — rendered as the pillar page intro under /knowledge. */
    public function description(): string
    {
        return match ($this) {
            self::Fundamentals => 'How timber behaves as a material — species groups, density, moisture, movement and durability.',
            self::Cameroon101 => 'Cameroon as a timber origin: producing regions, forest types, the production chain, ports and institutions.',
            self::Products => 'The traded forms of Cameroonian timber — logs, sawn timber, boules, veneer, plywood and finished components.',
            self::Processing => 'What happens between forest and container: sawmilling, drying, treatment and secondary processing.',
            self::Grading => 'How Cameroonian timber is graded and specified, and what a grade actually guarantees a buyer.',
            self::Buying => 'Sourcing Cameroonian timber: specifying an order, verifying a supplier, Incoterms, payment and inspection.',
            self::Export => 'Moving timber out of Cameroon — documentation, customs, phytosanitary requirements, ports and containerisation.',
            self::Compliance => 'The legal frameworks that govern Cameroonian timber: EUDR, FLEGT, CITES, SIGIF II and chain of custody.',
            self::Sustainability => 'Certification, legal sourcing, forest management and what sustainability claims can and cannot assert.',
            self::Logistics => 'Freight, routing, packing, insurance and the practical mechanics of shipping timber internationally.',
            self::Business => 'Contracts, pricing, risk, financing and the commercial mechanics of the timber trade.',
        };
    }

    /** Canonical pillar page URL. The route is registered by the Knowledge Centre routes. */
    public function url(): string
    {
        return route('knowledge.hub', $this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $h) => [$h->value => $h->label()])->all();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
