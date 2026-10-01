<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local/staging demo dataset: a well-known super admin
 * (admin@cameroontimberhub.test / "password"), demo companies, products,
 * RFQs, quotes, orders and messaging. NEVER runs in production — it refuses
 * outright there, even when called explicitly with --class.
 *
 * Assumes ReferenceDataSeeder has already run (roles, plans, species...).
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoDataSeeder must never run in production (it creates a super admin with a known password).');
        }

        // Local/dev super admin for the /admin Filament panel (password: "password").
        User::factory()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@cameroontimberhub.test',
        ])->assignRole('super_admin');

        $this->call([
            DemoCompanySeeder::class,
            ProductSeeder::class,
            DomesticMarketDemoSeeder::class,
            DomesticServiceDemoSeeder::class,
            QuoteSeeder::class,
            OrderSeeder::class,
            MessagingSeeder::class,
            CompanyCoordinatesSeeder::class,
        ]);
    }
}
