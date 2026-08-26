<?php

namespace App\Enums;

/**
 * Editorial category for /insights articles.
 *
 * The categories are the four resource links the footer already advertises
 * (Timber Grades, Export Guide, Market Insights, Blog) widened into a set that
 * covers the whole funnel: species knowledge, legality/compliance, logistics
 * and buying process.
 */
enum ArticleCategory: string
{
    case Guides = 'guides';
    case Species = 'species';
    case Regulation = 'regulation';
    case Export = 'export';
    case Market = 'market';
    case Buying = 'buying';

    public function label(): string
    {
        return match ($this) {
            self::Guides => 'Guides',
            self::Species => 'Species',
            self::Regulation => 'Regulation & Compliance',
            self::Export => 'Export & Logistics',
            self::Market => 'Market Insights',
            self::Buying => 'Buying',
        };
    }

    /** Short form used in card pills where the full label would wrap. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Regulation => 'Compliance',
            self::Export => 'Export',
            self::Market => 'Market',
            default => $this->label(),
        };
    }

    /** One-line description — rendered as the category intro on /insights. */
    public function description(): string
    {
        return match ($this) {
            self::Guides => 'Practical, step-by-step guides to grading, measuring and specifying Cameroonian timber.',
            self::Species => 'Species profiles: properties, working characteristics and what each one is bought for.',
            self::Regulation => 'FLEGT, CITES, EUDR and Cameroonian forestry law explained for buyers and exporters.',
            self::Export => 'Documentation, packing, freight and port procedure for timber leaving Douala and Kribi.',
            self::Market => 'Prices, demand signals and supply conditions across the Cameroon timber trade.',
            self::Buying => 'How to source safely: supplier due diligence, RFQs, payment terms and inspection.',
        };
    }

    /** Filament badge colour key. */
    public function color(): string
    {
        return match ($this) {
            self::Guides => 'info',
            self::Species => 'success',
            self::Regulation => 'danger',
            self::Export => 'warning',
            self::Market => 'primary',
            self::Buying => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
