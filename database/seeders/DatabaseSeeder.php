<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Entry point for `php artisan db:seed`.
 *
 * Production-safe: reference data (roles, plans, tax rules, document types,
 * species, CMS pages, glossary) always runs; the demo dataset — including the
 * well-known `admin@cameroontimberhub.test` / "password" super admin — runs
 * ONLY outside production. Create real staff with `php artisan admin:create`.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        if (app()->isProduction()) {
            $this->command?->warn('Production environment: skipping DemoDataSeeder (no default admin, no demo data). Use `php artisan admin:create`.');

            return;
        }

        $this->call(DemoDataSeeder::class);
    }
}
