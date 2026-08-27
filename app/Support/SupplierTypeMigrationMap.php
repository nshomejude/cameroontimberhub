<?php

namespace App\Support;

/**
 * The single source of truth for translating old App\Enums\SupplierType
 * values to the new App\Enums\OrganisationType values, during the additive
 * migration described in
 * docs/superpowers/plans/2026-08-27-organisation-type-migration.md.
 *
 * Deliberately covers only the two SupplierType values with an unambiguous
 * OrganisationType equivalent. `exporter`, `trader`, and `service_provider`
 * are intentionally absent — seeing them here would look like a completed
 * decision, when the "Scope decision" section of that plan documents three
 * unresolved options for exporter/trader specifically and flags
 * service_provider as equally unresolved.
 *
 * When the human decision is made (see the plan's "Scope decision" section),
 * extend this array — that is the ONLY file that needs a code change to
 * pick up the new mapping; App\Console\Commands\BackfillOrganisationType
 * already iterates this map generically. Re-run
 * `php artisan companies:backfill-organisation-type` afterwards; it is
 * idempotent and never overwrites a `type` that is already set (see its
 * WHERE clause), so re-running after extending this map only fills in rows
 * that are still NULL.
 */
final class SupplierTypeMigrationMap
{
    /**
     * @var array<string, string> old SupplierType value => new OrganisationType value
     */
    public const MAP = [
        'manufacturer' => 'manufacturer',
        'logistics_provider' => 'logistics',
    ];
}
