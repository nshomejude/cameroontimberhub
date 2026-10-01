<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Launch decision: Free-plan companies receive RFQ leads until an admin turns
 * `leads_receive` off in Admin -> Plans (the single source of truth). Sets the
 * key on the existing 'free' row only when it is currently false/missing —
 * idempotent, and a no-op on a fresh database (PlanSeeder already seeds true).
 */
return new class extends Migration
{
    public function up(): void
    {
        $plan = DB::table('plans')->where('slug', 'free')->first();

        if ($plan === null) {
            return;
        }

        $features = json_decode((string) $plan->features, true) ?: [];

        if (($features['leads_receive'] ?? false) === true) {
            return;
        }

        $features['leads_receive'] = true;

        DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
    }

    public function down(): void
    {
        // Data-only change; an admin can toggle it back in the Plans resource.
    }
};
