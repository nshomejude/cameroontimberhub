<?php

/**
 * API-First plan (docs/superpowers/plans/2026-09-09-api-first-ddd-cqrs-event-driven.md),
 * Phase 3 follow-up called out in Phase 0 Task 0.4: API-key rate-limit
 * tiers wired to the real `plans`/`subscriptions` billing model instead of
 * a hardcoded 'standard' default.
 *
 * `plans.slug` is the existing identifying field on App\Models\Plan (see
 * database/seeders/PlanSeeder.php) — reused here rather than inventing a
 * new concept. A simple config map (not a new `plans` column, and not a
 * new key inside Plan's `features` jsonb) was chosen because the tier
 * mapping is a cross-cutting API-product concern, not a plan feature flag
 * a buyer/seller ever sees on a pricing page, and because most of the
 * ~20 seeded plans (buy/deal/export/learn/analyze segments) have nothing
 * to do with API access at all — a central map is easier to audit and
 * change than scattering an `api_rate_limit_tier` feature key across
 * every segment's PlanSeeder entry.
 */
return [

    'rate_limit_tiers' => [

        // Fallback for a company with no active subscription, or whose
        // active plan's slug isn't listed below (e.g. education/learn
        // plans that have no API-relevant tier at all).
        'default' => 'basic',

        'by_plan_slug' => [
            // Sell-segment (exporter/seller profile plans).
            'enterprise' => 'elevated',
            'professional' => 'standard',
            'free' => 'basic',

            // Buy-segment.
            'corporate-buyer' => 'elevated',
            'business-buyer' => 'standard',
            'buyer-plus' => 'standard',
            'buyer-free' => 'basic',

            // Deal-segment.
            'dealer-network' => 'elevated',
            'dealer-pro' => 'standard',
            'dealer-free' => 'basic',

            // Export-segment.
            'exporter-enterprise' => 'elevated',
            'exporter-business' => 'standard',
            'exporter-professional' => 'standard',

            // Market intelligence / analyze-segment.
            'market-intelligence-enterprise' => 'elevated',
            'market-intelligence-professional' => 'standard',
        ],
    ],

];
