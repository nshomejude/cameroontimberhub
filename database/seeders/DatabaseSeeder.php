<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Local/dev super admin for the /admin Filament panel (password: "password").
        User::factory()->create([
            'name' => 'Platform Admin',
            'email' => 'admin@cameroontimberhub.test',
        ])->assignRole('super_admin');

        $this->call([
            PlanSeeder::class,
            TaxRuleSeeder::class,
            DocumentTypeSeeder::class,
            SpeciesSeeder::class,
            PageSeeder::class,
            GlossaryTermSeeder::class,
            DemoCompanySeeder::class,
            ProductSeeder::class,
            DomesticMarketDemoSeeder::class,
            DomesticServiceDemoSeeder::class,
            QuoteSeeder::class,
            OrderSeeder::class,
            MessagingSeeder::class,
        ]);
    }
}
