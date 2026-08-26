<?php

namespace App\Enums;

/**
 * Catalogue product type — the processing form a marketplace listing is traded
 * in. Drives the "Product Type" row on the product detail page and the type
 * facet on /marketplace.
 *
 * Covers the forms Cameroonian timber is actually shipped in, from round logs
 * through sawn and squared stock to finished panels and biomass.
 */
enum ProductType: string
{
    case SawnTimber = 'sawn_timber';
    case Logs = 'logs';
    case Veneer = 'veneer';
    case Flooring = 'flooring';
    case Decking = 'decking';
    case Mouldings = 'mouldings';
    case Plywood = 'plywood';
    case Beams = 'beams';
    case Planks = 'planks';
    case Boules = 'boules';
    case Squares = 'squares';
    case Sleepers = 'sleepers';
    case Poles = 'poles';
    case Slabs = 'slabs';
    case LaminatedPanels = 'laminated_panels';
    case Charcoal = 'charcoal';

    public function label(): string
    {
        return match ($this) {
            self::SawnTimber => 'Sawn Timber',
            self::Logs => 'Logs',
            self::Veneer => 'Veneer',
            self::Flooring => 'Flooring',
            self::Decking => 'Decking',
            self::Mouldings => 'Mouldings',
            self::Plywood => 'Plywood',
            self::Beams => 'Beams',
            self::Planks => 'Planks',
            self::Boules => 'Boules / Through-and-through',
            self::Squares => 'Squares / Scantlings',
            self::Sleepers => 'Railway Sleepers',
            self::Poles => 'Poles & Posts',
            self::Slabs => 'Live-edge Slabs',
            self::LaminatedPanels => 'Laminated / Edge-glued Panels',
            self::Charcoal => 'Charcoal & Biomass',
        };
    }

    /** One-line explanation of the form, used as helper text and for SEO/AEO. */
    public function description(): string
    {
        return match ($this) {
            self::SawnTimber => 'Timber sawn to standard commercial sizes, air-dried or kiln-dried, sold by volume.',
            self::Logs => 'Round, unprocessed logs cut to export lengths and graded at the log yard.',
            self::Veneer => 'Thin sheets peeled or sliced from a log for panel facing and decorative overlays.',
            self::Flooring => 'Machined solid or engineered flooring boards, usually tongue-and-groove profiled.',
            self::Decking => 'Exterior-grade profiled boards for terraces, walkways and marine decking.',
            self::Mouldings => 'Machined profiles such as skirting, architrave, beading and window sections.',
            self::Plywood => 'Glued multi-ply panels built from veneer layers, in commercial or marine grades.',
            self::Beams => 'Large-section structural members for framing, roofing and heavy construction.',
            self::Planks => 'Wide sawn boards of moderate thickness for general joinery, furniture and shelving.',
            self::Boules => 'A whole log flitch-sawn through-and-through and restacked in its original order.',
            self::Squares => 'Square or rectangular scantlings and billets cut for turning, framing and remanufacture.',
            self::Sleepers => 'Heavy, dense sections cut for railway track, landscaping and retaining walls.',
            self::Poles => 'Round poles and posts for fencing, power lines, piling and construction support.',
            self::Slabs => 'Full-width slabs with the natural edge retained, for tabletops and feature pieces.',
            self::LaminatedPanels => 'Edge-glued or laminated solid-wood panels for worktops, stair treads and shelving.',
            self::Charcoal => 'Charcoal, briquettes and wood biomass produced from timber residues and offcuts.',
        };
    }

    /** Filament badge color key. */
    public function color(): string
    {
        return match ($this) {
            self::SawnTimber, self::Beams, self::Planks, self::Squares => 'success',
            self::Logs, self::Boules, self::Poles => 'warning',
            self::Veneer, self::Plywood, self::LaminatedPanels => 'info',
            self::Sleepers, self::Charcoal => 'danger',
            self::Flooring, self::Decking, self::Mouldings, self::Slabs => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
