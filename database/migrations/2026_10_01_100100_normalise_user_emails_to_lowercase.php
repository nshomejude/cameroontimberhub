<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Canonicalises users.email to trimmed lower-case and, on PostgreSQL, adds a
 * unique index on lower(email) so "Alice@x.com" and "alice@x.com" can never
 * be two accounts.
 *
 * Guarded: if existing rows collide case-insensitively, the colliding rows are
 * left untouched and the index is NOT created — the duplicates are logged for
 * a human to merge, and the migration still succeeds.
 */
return new class extends Migration
{
    private const INDEX = 'users_email_lower_unique';

    public function up(): void
    {
        $duplicates = DB::table('users')
            ->selectRaw('lower(trim(email)) as normalised')
            ->groupByRaw('lower(trim(email))')
            ->havingRaw('count(*) > 1')
            ->pluck('normalised')
            ->all();

        DB::table('users')
            ->whereRaw('email <> lower(trim(email))')
            ->when($duplicates !== [], fn ($q) => $q->whereRaw(
                'lower(trim(email)) not in ('.implode(',', array_fill(0, count($duplicates), '?')).')',
                $duplicates,
            ))
            ->update(['email' => DB::raw('lower(trim(email))')]);

        if ($duplicates !== []) {
            Log::warning('users.email has case-insensitive duplicates; lower(email) unique index NOT created.', [
                'emails' => $duplicates,
            ]);

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX.' ON users (lower(email))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }
};
