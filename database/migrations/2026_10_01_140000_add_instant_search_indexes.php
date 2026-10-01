<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Instant (type-ahead) search support — see SearchSuggestService.
 *
 * Everything here is best-effort: managed Postgres hosts may refuse
 * CREATE EXTENSION / CREATE FUNCTION for the app role. Each step is wrapped
 * and logged rather than failing the deploy; SearchSuggestService detects
 * at runtime whether `ct_unaccent()` exists and falls back to lower().
 *
 * Runs outside a transaction on purpose: in Postgres one failed statement
 * aborts the whole transaction, which would defeat the per-step try/catch.
 *
 * `ct_unaccent()` is an IMMUTABLE wrapper around unaccent() (which is only
 * STABLE) so it can be used in expression indexes.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, array{0: string, 1: string}> index name => [table, column] */
    private const INDEXES = [
        'products_name_unaccent_trgm' => ['products', 'name'],
        'companies_legal_name_unaccent_trgm' => ['companies', 'legal_name'],
        'companies_trade_name_unaccent_trgm' => ['companies', 'trade_name'],
        'species_common_name_unaccent_trgm' => ['species', 'common_name'],
        'species_scientific_name_unaccent_trgm' => ['species', 'scientific_name'],
        'species_french_name_unaccent_trgm' => ['species', 'french_name'],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->attempt('CREATE EXTENSION IF NOT EXISTS unaccent');
        $this->attempt('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        $hasUnaccent = $this->attempt(<<<'SQL'
            CREATE OR REPLACE FUNCTION ct_unaccent(text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
            AS $$ SELECT unaccent('unaccent'::regdictionary, $1) $$
        SQL);

        $expr = $hasUnaccent ? 'ct_unaccent(lower(%s))' : 'lower(%s)';

        foreach (self::INDEXES as $name => [$table, $column]) {
            $this->attempt(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s USING gin ((%s) gin_trgm_ops)',
                $name, $table, sprintf($expr, $column),
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            $this->attempt("DROP INDEX IF EXISTS {$name}");
        }

        $this->attempt('DROP FUNCTION IF EXISTS ct_unaccent(text)');
        // The unaccent extension itself is left in place: other objects may depend on it.
    }

    private function attempt(string $sql): bool
    {
        try {
            DB::statement($sql);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Instant-search migration step skipped', ['sql' => $sql, 'error' => $e->getMessage()]);

            return false;
        }
    }
};
